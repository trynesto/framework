<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * String must be entirely lowercase.
 *
 *   #[IsLowercase]
 *   public string $slug;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsLowercase extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = is_string($value) && $value === mb_strtolower($value);

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must be a lowercase string", $value);
    }
}
