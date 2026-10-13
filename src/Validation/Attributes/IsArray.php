<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Value must be an array.
 *
 *   #[IsArray]
 *   public array $tags = [];
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsArray extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        return is_array($value) ? null : $this->fail($ctx, "{$ctx->property} must be an array", $value);
    }
}
