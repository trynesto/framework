<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * String must represent a number ("42", "-3.5", "1e3").
 *
 *   #[IsNumberString]
 *   public string $amount;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsNumberString extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = is_string($value) && is_numeric($value);

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must be a number string", $value);
    }
}
