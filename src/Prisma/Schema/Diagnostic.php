<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema;

/**
 * One error or warning about a schema, with its location. `render()` prints it the way the Prisma CLI does:
 *
 *   error: Error validating field `author` in model `Post`: The relation field `author` on model `Post` is missing ...
 *     -->  prisma/schema.prisma:20
 *      |
 *   19 |   authorId Int
 *   20 |   author   User @relation(fields: [authorId], references: [id])
 *   21 | }
 *      |
 */
final class Diagnostic
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    public function __construct(
        public readonly string $message,
        public readonly ?Span $span = null,
        public readonly string $severity = self::ERROR,
    ) {}

    public static function warning(string $message, ?Span $span = null): self
    {
        return new self($message, $span, self::WARNING);
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }

    /** "error: message  -->  file:line:column" plus the surrounding source lines. */
    public function render(string $source = '', string $file = 'schema.prisma'): string
    {
        $out = $this->severity . ': ' . $this->message . "\n";
        if ($this->span === null) {
            return $out;
        }
        $out .= '  -->  ' . $file . ':' . $this->span->line . ':' . $this->span->column . "\n";
        if ($source === '') {
            return $out;
        }
        $lines = preg_split('/\r\n|\n|\r/', $source) ?: [];
        $first = max(1, $this->span->line - 1);
        $last = min(count($lines), $this->span->line + 1);
        $width = strlen((string) $last);
        $gutter = str_repeat(' ', $width) . ' | ';
        $out .= rtrim($gutter) . "\n";
        for ($n = $first; $n <= $last; $n++) {
            $out .= str_pad((string) $n, $width, ' ', STR_PAD_LEFT) . ' | ' . $lines[$n - 1] . "\n";
            if ($n === $this->span->line) {
                $length = $this->span->endLine === $this->span->line ? max(1, $this->span->endColumn - $this->span->column) : max(1, strlen($lines[$n - 1]) - $this->span->column + 1);
                $out .= $gutter . str_repeat(' ', max(0, $this->span->column - 1)) . str_repeat('^', $length) . "\n";
            }
        }
        $out .= rtrim($gutter) . "\n";

        return $out;
    }

    public function __toString(): string
    {
        return ($this->span !== null ? $this->span . ': ' : '') . $this->message;
    }
}
