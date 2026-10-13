<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema;

use Nesto\Prisma\Schema\Ast\Argument;
use Nesto\Prisma\Schema\Ast\ArgumentList;
use Nesto\Prisma\Schema\Ast\ArrayValue;
use Nesto\Prisma\Schema\Ast\Attribute;
use Nesto\Prisma\Schema\Ast\Block;
use Nesto\Prisma\Schema\Ast\Comment;
use Nesto\Prisma\Schema\Ast\ConstantValue;
use Nesto\Prisma\Schema\Ast\EmptyLine;
use Nesto\Prisma\Schema\Ast\EnumValue;
use Nesto\Prisma\Schema\Ast\Expression;
use Nesto\Prisma\Schema\Ast\Field;
use Nesto\Prisma\Schema\Ast\FieldType;
use Nesto\Prisma\Schema\Ast\FunctionCall;
use Nesto\Prisma\Schema\Ast\Node;
use Nesto\Prisma\Schema\Ast\NumericValue;
use Nesto\Prisma\Schema\Ast\Property;
use Nesto\Prisma\Schema\Ast\SchemaAst;
use Nesto\Prisma\Schema\Ast\StringValue;

/**
 * Prisma Schema Language parser: source → SchemaAst. It recovers line by line, so one run reports every
 * syntax error (Diagnostic with line:column) instead of stopping at the first.
 *
 *   $parser = new Parser($source);
 *   $ast = $parser->parse();
 *   $parser->diagnostics;      // syntax errors
 *
 * Grammar (one declaration per line):
 *
 *   datasource db { provider = "postgresql"  url = env("DATABASE_URL") }
 *   generator client { provider = "nesto-client" }
 *   model User { id Int @id @default(autoincrement())  posts Post[]  @@map("users") }
 *   view UserInfo { id Int @unique }
 *   enum Role { USER  ADMIN @map("admin") }
 *   type Address { street String }       // composite types (MongoDB only: parsed, rejected by validation)
 *
 * `//` comments are kept as Comment nodes or trailing comments, `///` comments become the documentation
 * of the next declaration.
 */
final class Parser
{
    private const KEYWORDS = ['datasource', 'generator', 'model', 'enum', 'view', 'type'];

    /** @var list<Token> */
    private array $tokens;
    private int $pos = 0;
    /** @var list<Diagnostic> */
    public array $diagnostics = [];

    public function __construct(string $source)
    {
        $lexer = new Lexer($source);
        $this->tokens = $lexer->tokenize();
        $this->diagnostics = $lexer->diagnostics;
    }

    public static function parseSource(string $source): SchemaAst
    {
        return (new self($source))->parse();
    }

    public function parse(): SchemaAst
    {
        $items = [];
        $docs = [];
        $lineStart = true;
        while (! $this->peek()->is(Token::EOF)) {
            $token = $this->peek();
            if ($token->is(Token::NEWLINE)) {
                $this->pos++;
                if ($lineStart && $docs === []) {
                    $items[] = new EmptyLine($token->span);
                }
                $lineStart = true;
                continue;
            }
            $lineStart = false;
            if ($token->is(Token::COMMENT)) {
                $this->pos++;
                $items[] = new Comment($token->value, $token->span);
                continue;
            }
            if ($token->is(Token::DOC_COMMENT)) {
                $this->pos++;
                $docs[] = $token->value;
                continue;
            }
            if ($token->is(Token::IDENT) && in_array($token->value, self::KEYWORDS, true)) {
                $block = $this->block(self::documentation($docs));
                $docs = [];
                if ($block !== null) {
                    $items[] = $block;
                }
                continue;
            }
            $this->error('Error validating: This line is invalid. It does not start with any known Prisma schema keyword.', $this->lineSpan());
            $this->skipLine();
            $docs = [];
        }

        return new SchemaAst($items);
    }

    // ---- blocks -----------------------------------------------------------------------------------

    private function block(?string $documentation): ?Block
    {
        $keyword = $this->next();
        $kind = $keyword->value;
        $name = $this->peek();
        if (! $name->is(Token::IDENT)) {
            $this->error(sprintf('Error validating: This %s declaration is invalid. It is missing a name.', $kind === 'datasource' || $kind === 'generator' ? $kind : ucfirst($kind)), $this->lineSpan());
            $this->skipBlock();

            return null;
        }
        $this->pos++;
        if (! $this->peek()->isPunct('{')) {
            $this->error(sprintf('Error validating %s "%s": Expected "{" after the name, found %s.', $kind, $name->value, $this->peek()->describe()), $this->peek()->span);
            $this->skipBlock();

            return null;
        }
        $this->pos++;
        $trailing = null;
        if ($this->peek()->is(Token::COMMENT)) {
            $trailing = $this->next()->value;
        }
        if (! $this->peek()->is(Token::NEWLINE) && ! $this->peek()->isPunct('}')) {
            $this->error(sprintf('Error validating %s "%s": Expected a new line after "{".', $kind, $name->value), $this->peek()->span);
        }

        $members = [];
        $docs = [];
        $lineStart = true;
        $closed = null;
        while (true) {
            $token = $this->peek();
            if ($token->is(Token::EOF)) {
                break;
            }
            if ($token->isPunct('}')) {
                $closed = $this->next();
                break;
            }
            if ($token->is(Token::NEWLINE)) {
                $this->pos++;
                if ($lineStart && $docs === [] && $members !== []) {
                    $members[] = new EmptyLine($token->span);
                }
                $lineStart = true;
                continue;
            }
            $wasLineStart = $lineStart;
            $lineStart = false;
            if ($token->is(Token::COMMENT)) {
                $this->pos++;
                $members[] = new Comment($token->value, $token->span);
                continue;
            }
            if ($token->is(Token::DOC_COMMENT)) {
                $this->pos++;
                $docs[] = $token->value;
                continue;
            }
            if (! $wasLineStart) {
                // something after a complete declaration on the same line
                $this->invalidLine($kind, $name->value);
                continue;
            }
            if ($token->is(Token::IDENT) && in_array($token->value, self::KEYWORDS, true) && $this->peekAt(1)->is(Token::IDENT) && $this->peekAt(2)->isPunct('{')) {
                break; // the next block starts: this one is missing its "}"
            }
            $member = match (true) {
                $token->isPunct('@') && $this->peekAt(1)->isPunct('@') => $this->blockAttribute($kind, $name->value, self::documentation($docs)),
                $kind === Block::DATASOURCE || $kind === Block::GENERATOR => $this->property($kind, $name->value, self::documentation($docs)),
                $kind === Block::ENUM => $this->enumValue($name->value, self::documentation($docs)),
                default => $this->field($kind, $name->value, self::documentation($docs)),
            };
            $docs = [];
            if ($member !== null) {
                $members[] = $member;
            }
        }
        if ($closed === null) {
            $this->error(sprintf('Error validating %s "%s": This block is invalid. It is missing a closing curly brace ("}").', $kind, $name->value), $keyword->span);
            $end = $this->peek()->span;
        } else {
            $end = $closed->span;
            if ($this->peek()->is(Token::COMMENT)) {
                $this->pos++;
            }
        }
        while ($members !== [] && end($members) instanceof EmptyLine) {
            array_pop($members);
        }

        return new Block($kind, $name->value, $name->span, $members, Span::cover($keyword->span, $end), $documentation, $trailing);
    }

    private function field(string $kind, string $blockName, ?string $documentation): ?Field
    {
        $start = $this->pos;
        $name = $this->peek();
        if (! $name->is(Token::IDENT)) {
            $this->invalidLine($kind, $blockName);

            return null;
        }
        $this->pos++;
        if ($this->atLineEnd()) {
            $this->error(sprintf('Error validating %s "%s": This field declaration is invalid. It is either missing a name or a type.', $kind, $blockName), $name->span);
            $this->skipLine();

            return null;
        }
        $type = $this->fieldType($kind, $blockName);
        if ($type === null) {
            $this->pos = $start;
            $this->invalidLine($kind, $blockName);

            return null;
        }
        $attributes = [];
        while ($this->peek()->isPunct('@') && ! $this->peekAt(1)->isPunct('@')) {
            $attribute = $this->attribute(false);
            if ($attribute === null) {
                $this->pos = $start;
                $this->invalidLine($kind, $blockName);

                return null;
            }
            $attributes[] = $attribute;
        }
        $trailing = $this->trailingComment();
        if (! $this->atLineEnd()) {
            $this->pos = $start;
            $this->invalidLine($kind, $blockName);

            return null;
        }
        $last = $this->tokens[$this->pos - 1];

        return new Field($name->value, $name->span, $type, $attributes, Span::cover($name->span, $last->span), $documentation, $trailing);
    }

    private function fieldType(string $kind, string $blockName): ?FieldType
    {
        $token = $this->peek();
        if (! $token->is(Token::IDENT)) {
            return null;
        }
        $this->pos++;
        $unsupported = null;
        $end = $token->span;
        if ($token->value === 'Unsupported' && $this->peek()->isPunct('(')) {
            $this->pos++;
            $argument = $this->peek();
            if (! $argument->is(Token::STRING) || ! $this->peekAt(1)->isPunct(')')) {
                return null;
            }
            $unsupported = $argument->value;
            $this->pos++;
            $end = $this->next()->span;
        }
        $list = false;
        $optional = false;
        if ($this->peek()->isPunct('[')) {
            if (! $this->peekAt(1)->isPunct(']')) {
                return null;
            }
            $this->pos++;
            $end = $this->next()->span;
            $list = true;
        }
        if ($this->peek()->isPunct('?')) {
            $end = $this->next()->span;
            $optional = true;
        }
        if ($this->peek()->isPunct('!')) {
            $this->error('Error validating: The required modifier "!" is not supported. Fields are required by default; use `Type?` for optional fields.', $this->peek()->span);
            $end = $this->next()->span;
        }
        if ($list && $optional) {
            $this->error('Optional lists are not supported. Use either `Type[]` or `Type?`.', Span::cover($token->span, $end));
            $optional = false;
        }
        if ($this->peek()->isPunct('[') && $this->peekAt(1)->isPunct(']')) {
            $this->error('Optional lists are not supported. Use either `Type[]` or `Type?`.', Span::cover($token->span, $this->peekAt(1)->span));
            $this->pos += 2;
        }

        return new FieldType($token->value, $optional, $list, Span::cover($token->span, $end), $unsupported);
    }

    private function enumValue(string $blockName, ?string $documentation): ?EnumValue
    {
        $start = $this->pos;
        $name = $this->peek();
        if (! $name->is(Token::IDENT)) {
            $this->invalidLine(Block::ENUM, $blockName);

            return null;
        }
        $this->pos++;
        $attributes = [];
        while ($this->peek()->isPunct('@') && ! $this->peekAt(1)->isPunct('@')) {
            $attribute = $this->attribute(false);
            if ($attribute === null) {
                $this->pos = $start;
                $this->invalidLine(Block::ENUM, $blockName);

                return null;
            }
            $attributes[] = $attribute;
        }
        $trailing = $this->trailingComment();
        if (! $this->atLineEnd()) {
            $this->pos = $start;
            $this->invalidLine(Block::ENUM, $blockName);

            return null;
        }

        return new EnumValue($name->value, $attributes, Span::cover($name->span, $this->tokens[$this->pos - 1]->span), $documentation, $trailing);
    }

    private function property(string $kind, string $blockName, ?string $documentation): ?Property
    {
        $start = $this->pos;
        $key = $this->peek();
        if (! $key->is(Token::IDENT) || ! $this->peekAt(1)->isPunct('=')) {
            $this->invalidLine($kind, $blockName);

            return null;
        }
        $this->pos += 2;
        $value = $this->expression();
        if ($value === null) {
            $this->pos = $start;
            $this->invalidLine($kind, $blockName);

            return null;
        }
        $trailing = $this->trailingComment();
        if (! $this->atLineEnd()) {
            $this->pos = $start;
            $this->invalidLine($kind, $blockName);

            return null;
        }

        return new Property($key->value, $key->span, $value, Span::cover($key->span, $value->span), $documentation, $trailing);
    }

    private function blockAttribute(string $kind, string $blockName, ?string $documentation): ?Attribute
    {
        $start = $this->pos;
        $attribute = $this->attribute(true, $documentation);
        if ($attribute === null || ! $this->atLineEnd(true)) {
            $this->pos = $start;
            $this->invalidLine($kind, $blockName);

            return null;
        }
        $trailing = $this->trailingComment();
        if ($trailing !== null) {
            $attribute = new Attribute($attribute->name, $attribute->arguments, true, $attribute->span, $attribute->nameSpan, $attribute->hasParentheses, $trailing, $documentation);
        }

        return $attribute;
    }

    // ---- attributes and expressions ---------------------------------------------------------------

    private function attribute(bool $block, ?string $documentation = null): ?Attribute
    {
        $at = $this->next();
        if ($block) {
            $this->next();
        }
        $nameToken = $this->peek();
        if (! $nameToken->is(Token::IDENT)) {
            return null;
        }
        $this->pos++;
        $name = $nameToken->value;
        $nameEnd = $nameToken->span;
        while ($this->peek()->isPunct('.') && $this->peekAt(1)->is(Token::IDENT)) {
            $this->pos++;
            $part = $this->next();
            $name .= '.' . $part->value;
            $nameEnd = $part->span;
        }
        $nameSpan = Span::cover($nameToken->span, $nameEnd);
        $arguments = new ArgumentList();
        $end = $nameEnd;
        $parentheses = false;
        if ($this->peek()->isPunct('(') && $this->peek()->span->line === $nameEnd->line && $this->peek()->span->column === $nameEnd->endColumn) {
            $parsed = $this->argumentList();
            if ($parsed === null) {
                return null;
            }
            [$arguments, $end] = $parsed;
            $parentheses = true;
        }

        return new Attribute($name, $arguments, $block, Span::cover($at->span, $end), $nameSpan, $parentheses, null, $documentation);
    }

    /** `( args )` at the current position. @return array{0: ArgumentList, 1: Span}|null */
    private function argumentList(): ?array
    {
        $this->pos++; // (
        $arguments = [];
        while (true) {
            $this->skipNewlines();
            if ($this->peek()->isPunct(')')) {
                return [new ArgumentList($arguments), $this->next()->span];
            }
            $argument = $this->argument();
            if ($argument === null) {
                return null;
            }
            $arguments[] = $argument;
            $this->skipNewlines();
            if ($this->peek()->isPunct(',')) {
                $this->pos++;
                continue;
            }
            if (! $this->peek()->isPunct(')')) {
                return null;
            }
        }
    }

    private function argument(): ?Argument
    {
        $token = $this->peek();
        if ($token->is(Token::IDENT) && $this->peekAt(1)->isPunct(':')) {
            $this->pos += 2;
            $value = $this->expression();

            return $value === null ? null : new Argument($token->value, $value, Span::cover($token->span, $value->span));
        }
        $value = $this->expression();

        return $value === null ? null : new Argument(null, $value, $value->span);
    }

    private function expression(): ?Expression
    {
        $token = $this->peek();
        if ($token->is(Token::STRING)) {
            $this->pos++;

            return new StringValue($token->value, $token->raw, $token->span);
        }
        if ($token->is(Token::NUMBER)) {
            $this->pos++;

            return new NumericValue($token->value, $token->span);
        }
        if ($token->isPunct('[')) {
            $this->pos++;
            $items = [];
            while (true) {
                $this->skipNewlines();
                if ($this->peek()->isPunct(']')) {
                    return new ArrayValue($items, Span::cover($token->span, $this->next()->span));
                }
                $item = $this->expression();
                if ($item === null) {
                    return null;
                }
                $items[] = $item;
                $this->skipNewlines();
                if ($this->peek()->isPunct(',')) {
                    $this->pos++;
                    continue;
                }
                if (! $this->peek()->isPunct(']')) {
                    return null;
                }
            }
        }
        if ($token->is(Token::IDENT)) {
            $this->pos++;
            $name = $token->value;
            $end = $token->span;
            while ($this->peek()->isPunct('.') && $this->peekAt(1)->is(Token::IDENT)) {
                $this->pos++;
                $end = $this->peek()->span;
                $name .= '.' . $this->next()->value;
            }
            if ($this->peek()->isPunct('(')) {
                $parsed = $this->argumentList();
                if ($parsed === null) {
                    return null;
                }

                return new FunctionCall($name, $parsed[0], Span::cover($token->span, $parsed[1]));
            }

            return new ConstantValue($name, Span::cover($token->span, $end));
        }

        return null;
    }

    // ---- helpers ----------------------------------------------------------------------------------

    private function trailingComment(): ?string
    {
        if ($this->peek()->is(Token::COMMENT) || $this->peek()->is(Token::DOC_COMMENT)) {
            $token = $this->next();

            return $token->is(Token::DOC_COMMENT) ? '/' . $token->value : $token->value;
        }

        return null;
    }

    private function atLineEnd(bool $allowComment = false): bool
    {
        $token = $this->peek();

        return $token->is(Token::NEWLINE) || $token->is(Token::EOF) || $token->isPunct('}')
            || ($allowComment && ($token->is(Token::COMMENT) || $token->is(Token::DOC_COMMENT)));
    }

    private function invalidLine(string $kind, string $blockName): void
    {
        $message = match ($kind) {
            Block::ENUM => sprintf('Error validating enum "%s": This line is not an enum value definition.', $blockName),
            Block::DATASOURCE => sprintf('Error validating datasource "%s": This line is not a valid definition within a datasource.', $blockName),
            Block::GENERATOR => sprintf('Error validating generator "%s": This line is not a valid definition within a generator.', $blockName),
            Block::VIEW => sprintf('Error validating view "%s": This line is not a valid field or attribute definition.', $blockName),
            Block::TYPE => sprintf('Error validating composite type "%s": This line is not a valid field or attribute definition.', $blockName),
            default => sprintf('Error validating model "%s": This line is not a valid field or attribute definition.', $blockName),
        };
        $this->error($message, $this->lineSpan());
        $this->skipLine();
    }

    /** From the current token to the last token on its line. */
    private function lineSpan(): Span
    {
        $first = $this->peek();
        $last = $first;
        for ($i = $this->pos; isset($this->tokens[$i]) && ! $this->tokens[$i]->is(Token::NEWLINE) && ! $this->tokens[$i]->is(Token::EOF); $i++) {
            $last = $this->tokens[$i];
        }

        return Span::cover($first->span, $last->span);
    }

    private function skipLine(): void
    {
        while (! $this->peek()->is(Token::NEWLINE) && ! $this->peek()->is(Token::EOF)) {
            if ($this->peek()->isPunct('}') && $this->peekAt(1)->is(Token::NEWLINE) && $this->pos > 0 && $this->tokens[$this->pos - 1]->is(Token::NEWLINE)) {
                return; // a block's closing brace on its own line
            }
            $this->pos++;
        }
    }

    /** Skip a broken block header and its body, if it has one. */
    private function skipBlock(): void
    {
        $this->skipLine();
        $depth = 0;
        $start = $this->pos;
        while (! $this->peek()->is(Token::EOF)) {
            $token = $this->next();
            if ($token->isPunct('{')) {
                $depth++;
            } elseif ($token->isPunct('}')) {
                if ($depth === 0) {
                    return;
                }
                $depth--;
            } elseif ($token->is(Token::IDENT) && in_array($token->value, self::KEYWORDS, true) && $this->tokens[$this->pos - 2]->is(Token::NEWLINE)) {
                $this->pos--;

                return;
            }
        }
        $this->pos = max($start, $this->pos);
    }

    private function skipNewlines(): void
    {
        while ($this->peek()->is(Token::NEWLINE) || $this->peek()->is(Token::COMMENT)) {
            $this->pos++;
        }
    }

    private function peek(): Token
    {
        return $this->tokens[$this->pos] ?? end($this->tokens);
    }

    private function peekAt(int $offset): Token
    {
        return $this->tokens[$this->pos + $offset] ?? end($this->tokens);
    }

    private function next(): Token
    {
        $token = $this->peek();
        if (! $token->is(Token::EOF)) {
            $this->pos++;
        }

        return $token;
    }

    private function error(string $message, Span $span): void
    {
        $this->diagnostics[] = new Diagnostic($message, $span);
    }

    /** @param list<string> $lines */
    private static function documentation(array $lines): ?string
    {
        if ($lines === []) {
            return null;
        }

        return implode("\n", array_map(fn (string $l) => str_starts_with($l, ' ') ? substr($l, 1) : $l, $lines));
    }
}
