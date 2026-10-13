<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * String must contain only letters and digits.
 *
 *   #[IsAlphanumeric]
 *   public string $code;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsAlphanumeric extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = is_string($value) && preg_match('/^[a-zA-Z0-9]+$/', $value) === 1;

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must contain only letters and numbers", $value);
    }
}
