<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Numeric value must be < 0.
 *
 *   #[IsNegative]
 *   public int $delta;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsNegative extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = (is_int($value) || is_float($value)) && $value < 0;

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must be a negative number", $value);
    }
}
