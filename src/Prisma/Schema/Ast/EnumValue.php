<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema\Ast;

use Nesto\Prisma\Schema\Span;

/** One value of an enum, with its attributes (`@map("x")`). */
final class EnumValue extends Node
{
    /** @param list<Attribute> $attributes */
    public function __construct(
        public readonly string $name,
        public readonly array $attributes,
        Span $span,
        public readonly ?string $documentation = null,
        public readonly ?string $trailingComment = null,
    ) {
        parent::__construct($span);
    }

    public function attribute(string $name): ?Attribute
    {
        foreach ($this->attributes as $attribute) {
            if ($attribute->name === $name) {
                return $attribute;
            }
        }

        return null;
    }
}
