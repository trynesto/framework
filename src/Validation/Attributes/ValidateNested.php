<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Recurses into a nested DTO (or a list of DTOs); their errors become `children` of this property's
 * ValidationError and flatten to "address.city must be a string" / "items.0.name must be a string".
 *
 *   #[ValidateNested]
 *   public AddressDto $address;
 *
 *   #[ValidateNested(each: true)]
 *   #[Type(ItemDto::class)]
 *   public array $items = [];
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ValidateNested extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        if (is_object($value)) {
            return null;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! is_object($item)) {
                    return $this->fail($ctx, "nested property {$ctx->property} must be either object or array", $value);
                }
            }

            return null;
        }

        return $this->fail($ctx, "nested property {$ctx->property} must be either object or array", $value);
    }
}
