<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema\Ast;

use Nesto\Prisma\Schema\Span;

/** A bare identifier used as a value: true, false, Cascade, Desc, an enum value, a field name (`title`). */
final class ConstantValue extends Expression
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
        return 'constant';
    }
}
