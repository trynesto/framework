<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema;

/**
 * Splits Prisma schema source into tokens. Newlines are tokens (PSL is line oriented: one field or attribute
 * per line); `//` comments and `///` documentation comments are kept so the formatter can print them back.
 * Identifiers are ASCII letters / digits / `_` / `-` starting with a letter or `_`; strings are double-quoted
 * with JSON-style escapes. Lexical errors become INVALID tokens and diagnostics.
 */
final class Lexer
{
    private const PUNCTUATION = ['{', '}', '(', ')', '[', ']', '=', ',', ':', '?', '@', '.', '!'];

    private int $pos = 0;
    private int $line = 1;
    private int $column = 1;
    /** @var list<Diagnostic> */
    public array $diagnostics = [];

    public function __construct(private readonly string $source) {}

    /** @return list<Token> */
    public function tokenize(): array
    {
        $tokens = [];
        $length = strlen($this->source);
        while ($this->pos < $length) {
            $char = $this->source[$this->pos];
            if ($char === "\r" && ($this->source[$this->pos + 1] ?? '') === "\n") {
                $this->advance(1);
                continue;
            }
            if ($char === "\n" || $char === "\r") {
                $tokens[] = new Token(Token::NEWLINE, "\n", Span::at($this->line, $this->column));
                $this->pos++;
                $this->line++;
                $this->column = 1;
                continue;
            }
            if ($char === ' ' || $char === "\t" || $char === "\f" || $char === "\v") {
                $this->advance(1);
                continue;
            }
            if ($char === '/' && ($this->source[$this->pos + 1] ?? '') === '/') {
                $tokens[] = $this->comment();
                continue;
            }
            if ($char === '"') {
                $tokens[] = $this->string();
                continue;
            }
            if (ctype_alpha($char) || $char === '_') {
                $tokens[] = $this->identifier();
                continue;
            }
            if (ctype_digit($char) || ($char === '-' && ctype_digit($this->source[$this->pos + 1] ?? ''))) {
                $tokens[] = $this->number();
                continue;
            }
            if (in_array($char, self::PUNCTUATION, true)) {
                $tokens[] = new Token(Token::PUNCT, $char, Span::at($this->line, $this->column), $char);
                $this->advance(1);
                continue;
            }
            // anything else: one invalid character (multi-byte UTF-8 kept whole)
            $bytes = 1;
            $ord = ord($char);
            if ($ord >= 0xC0) {
                $bytes = $ord >= 0xF0 ? 4 : ($ord >= 0xE0 ? 3 : 2);
            }
            $text = substr($this->source, $this->pos, $bytes);
            $tokens[] = new Token(Token::INVALID, $text, Span::at($this->line, $this->column), $text);
            $this->advance($bytes, 1);
        }
        $tokens[] = new Token(Token::EOF, '', Span::at($this->line, $this->column));

        return $tokens;
    }

    private function advance(int $bytes, ?int $columns = null): void
    {
        $this->pos += $bytes;
        $this->column += $columns ?? $bytes;
    }

    private function comment(): Token
    {
        $start = $this->pos;
        $line = $this->line;
        $column = $this->column;
        $end = strcspn($this->source, "\r\n", $start);
        $text = substr($this->source, $start, $end);
        $this->advance($end, mb_strlen($text));
        $doc = str_starts_with($text, '///');
        $value = $doc ? substr($text, 3) : substr($text, 2);

        return new Token($doc ? Token::DOC_COMMENT : Token::COMMENT, $value, new Span($line, $column, $line, $this->column), $text);
    }

    private function identifier(): Token
    {
        $line = $this->line;
        $column = $this->column;
        preg_match('/[A-Za-z0-9_\-]+/A', $this->source, $m, 0, $this->pos);
        $this->advance(strlen($m[0]));

        return new Token(Token::IDENT, $m[0], new Span($line, $column, $line, $this->column), $m[0]);
    }

    private function number(): Token
    {
        $line = $this->line;
        $column = $this->column;
        preg_match('/-?\d+(\.\d+)?([eE][+-]?\d+)?/A', $this->source, $m, 0, $this->pos);
        $this->advance(strlen($m[0]));

        return new Token(Token::NUMBER, $m[0], new Span($line, $column, $line, $this->column), $m[0]);
    }

    private function string(): Token
    {
        $line = $this->line;
        $column = $this->column;
        $start = $this->pos;
        $length = strlen($this->source);
        $i = $this->pos + 1;
        $value = '';
        $closed = false;
        while ($i < $length) {
            $char = $this->source[$i];
            if ($char === '"') {
                $closed = true;
                $i++;
                break;
            }
            if ($char === "\n" || $char === "\r") {
                break;
            }
            if ($char === '\\' && $i + 1 < $length) {
                $next = $this->source[$i + 1];
                switch ($next) {
                    case 'n': $value .= "\n"; $i += 2; continue 2;
                    case 't': $value .= "\t"; $i += 2; continue 2;
                    case 'r': $value .= "\r"; $i += 2; continue 2;
                    case 'b': $value .= "\x08"; $i += 2; continue 2;
                    case 'f': $value .= "\f"; $i += 2; continue 2;
                    case '"': $value .= '"'; $i += 2; continue 2;
                    case '\\': $value .= '\\'; $i += 2; continue 2;
                    case '/': $value .= '/'; $i += 2; continue 2;
                    case 'u':
                        $hex = substr($this->source, $i + 2, 4);
                        if (preg_match('/^[0-9a-fA-F]{4}$/', $hex) === 1) {
                            $value .= mb_chr((int) hexdec($hex), 'UTF-8');
                            $i += 6;
                            continue 2;
                        }
                        break;
                }
                $this->diagnostics[] = new Diagnostic(sprintf('Unknown escape sequence "\\%s" in string.', $next), Span::at($this->line, $this->column + mb_strlen(substr($this->source, $start, $i - $start))));
                $value .= $next;
                $i += 2;
                continue;
            }
            $value .= $char;
            $i++;
        }
        $raw = substr($this->source, $start, $i - $start);
        $this->advance($i - $start, mb_strlen($raw));
        $span = new Span($line, $column, $line, $this->column);
        if (! $closed) {
            $this->diagnostics[] = new Diagnostic('Unterminated string literal: a string must end with `"` on the same line.', $span);
        }

        return new Token(Token::STRING, $value, $span, $raw);
    }
}
