<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Value must be an integer (a PHP int; "5" is not accepted unless the transformer casted it).
 *
 *   #[IsInt]
 *   public int $age;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsInt extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        return is_int($value) ? null : $this->fail($ctx, "{$ctx->property} must be an integer number", $value);
    }
}
