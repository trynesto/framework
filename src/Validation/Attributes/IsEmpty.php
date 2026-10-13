<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Value must be '', null or undefined.
 *
 *   #[IsEmpty]
 *   public ?string $reserved = null;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsEmpty extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        return self::isEmptyValue($value) ? null : $this->fail($ctx, "{$ctx->property} must be empty", $value);
    }
}
