<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Marker: when the value is null or undefined every other constraint on the property is skipped.
 *
 *   #[IsOptional]
 *   #[IsInt]
 *   public ?int $page = null;      // null is fine, "abc" still fails IsInt
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsOptional extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        return null;
    }
}
