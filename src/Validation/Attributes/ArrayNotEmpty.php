<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Array must contain at least one element.
 *
 *   #[ArrayNotEmpty]
 *   public array $roles;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ArrayNotEmpty extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = is_array($value) && $value !== [];

        return $ok ? null : $this->fail($ctx, "{$ctx->property} should not be empty", $value);
    }
}
