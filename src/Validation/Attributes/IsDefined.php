<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Fails when the property is undefined (never initialised) or null. Unlike other constraints it also runs when `skipMissingProperties` is on.
 *
 *   #[IsDefined]
 *   public string $name;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsDefined extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        if ($ctx->defined && $value !== null) {
            return null;
        }

        return $this->fail($ctx, "{$ctx->property} should not be null or undefined", $value);
    }
}
