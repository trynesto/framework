<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema\Ast;

use Nesto\Prisma\Schema\Span;

/** 42, -1.5 (kept as written). */
final class NumericValue extends Expression
{
    public function __construct(public readonly string $value, Span $span)
    {
        parent::__construct($span);
    }

    public function toSource(): string
    {
        return $this->value;
    }

    public function kind(): string
    {
        return 'numeric';
    }

    public function isInteger(): bool
    {
        return preg_match('/^-?\d+$/', $this->value) === 1;
    }

    public function toNumber(): int|float
    {
        return $this->isInteger() && strlen(ltrim($this->value, '-')) < 19 ? (int) $this->value : (float) $this->value;
    }
}
