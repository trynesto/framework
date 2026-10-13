<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * String must contain only letters a-z / A-Z.
 *
 *   #[IsAlpha]
 *   public string $code;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsAlpha extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = is_string($value) && preg_match('/^[a-zA-Z]+$/', $value) === 1;

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must contain only letters (a-zA-Z)", $value);
    }
}
