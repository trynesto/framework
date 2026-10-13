<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Value must not be '', null or undefined.
 *
 *   #[IsNotEmpty]
 *   public string $title;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsNotEmpty extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        return self::isEmptyValue($value) ? $this->fail($ctx, "{$ctx->property} should not be empty", $value) : null;
    }
}
