<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema;

/** A source position range: 1-based line / column of the first character and of the end (exclusive). */
final class Span
{
    public function __construct(
        public readonly int $line,
        public readonly int $column,
        public readonly int $endLine,
        public readonly int $endColumn,
    ) {}

    public static function at(int $line, int $column): self
    {
        return new self($line, $column, $line, $column + 1);
    }

    /** From the start of $a to the end of $b. */
    public static function cover(self $a, self $b): self
    {
        return new self($a->line, $a->column, $b->endLine, $b->endColumn);
    }

    public function __toString(): string
    {
        return $this->line . ':' . $this->column;
    }
}
