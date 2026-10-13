<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema\Ast;

use Nesto\Prisma\Schema\Span;

/** `name: value` or a positional `value` of an attribute / function call. */
final class Argument extends Node
{
    public function __construct(public readonly ?string $name, public readonly Expression $value, Span $span)
    {
        parent::__construct($span);
    }

    public function toSource(): string
    {
        return ($this->name !== null ? $this->name . ': ' : '') . $this->value->toSource();
    }
}
