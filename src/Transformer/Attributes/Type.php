<?php

declare(strict_types=1);

namespace Nesto\Transformer\Attributes;

use Attribute;

/**
 * Declares the item type of an `array` property (or overrides the declared type of a scalar/object property)
 * so that ClassTransformer can hydrate it, exactly like class-transformer's @Type().
 *
 *   #[Type(AddressDto::class)]
 *   public array $addresses = [];          // plain list of arrays → list<AddressDto>
 *
 *   #[Type('int')]
 *   public mixed $count;                   // "5" → 5
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Type
{
    /** @param class-string|'int'|'float'|'bool'|'string'|'array' $type */
    public function __construct(public readonly string $type) {}
}
