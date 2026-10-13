<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Value must be a latitude (-90..90) as number or numeric string.
 *
 *   #[IsLatitude]
 *   public float $lat;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsLatitude extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) && (float) $value >= -90 && (float) $value <= 90;

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must be a latitude string or number", $value);
    }
}
