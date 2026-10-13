<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Value must be a longitude (-180..180) as number or numeric string.
 *
 *   #[IsLongitude]
 *   public float $lng;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsLongitude extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) && (float) $value >= -180 && (float) $value <= 180;

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must be a longitude string or number", $value);
    }
}
