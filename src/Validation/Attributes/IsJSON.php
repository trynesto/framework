<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * String must be valid JSON.
 *
 *   #[IsJSON]
 *   public string $payload;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsJSON extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = is_string($value) && json_validate($value);

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must be a json string", $value);
    }
}
