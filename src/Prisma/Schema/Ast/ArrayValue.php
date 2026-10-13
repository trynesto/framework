<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema\Ast;

use Nesto\Prisma\Schema\Span;

/** [a, b, c] */
final class ArrayValue extends Expression
{
    /** @param list<Expression> $items */
    public function __construct(public readonly array $items, Span $span)
    {
        parent::__construct($span);
    }

    public function toSource(): string
    {
        return '[' . implode(', ', array_map(fn (Expression $e) => $e->toSource(), $this->items)) . ']';
    }

    public function kind(): string
    {
        return 'array';
    }
}
