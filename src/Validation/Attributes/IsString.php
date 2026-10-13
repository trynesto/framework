<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Value must be a string.
 *
 *   #[IsString]
 *   public string $name;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsString extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        return is_string($value) ? null : $this->fail($ctx, "{$ctx->property} must be a string", $value);
    }
}
