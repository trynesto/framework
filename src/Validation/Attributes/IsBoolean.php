<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Value must be a bool.
 *
 *   #[IsBoolean]
 *   public bool $active;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsBoolean extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        return is_bool($value) ? null : $this->fail($ctx, "{$ctx->property} must be a boolean value", $value);
    }
}
