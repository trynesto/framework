<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Value must be a port number (0–65535) as int or numeric string.
 *
 *   #[IsPort]
 *   public int $port;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsPort extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = (is_int($value) || (is_string($value) && preg_match('/^\\d+$/', $value))) && (int) $value >= 0 && (int) $value <= 65535;

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must be a port", $value);
    }
}
