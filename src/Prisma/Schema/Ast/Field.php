<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema\Ast;

use Nesto\Prisma\Schema\Span;

/** A model / view field: name, type, attributes, `///` documentation and a trailing `//` comment. */
final class Field extends Node
{
    /** @param list<Attribute> $attributes */
    public function __construct(
        public readonly string $name,
        public readonly Span $nameSpan,
        public readonly FieldType $type,
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

    /** @return list<Attribute> */
    public function attributes(string $name): array
    {
        return array_values(array_filter($this->attributes, fn (Attribute $a) => $a->name === $name));
    }
}
