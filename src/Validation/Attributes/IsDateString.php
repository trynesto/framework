<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Value must be an ISO 8601 date string ("2024-01-31", "2024-01-31T10:00:00Z", ...).
 *
 *   #[IsDateString]
 *   public string $startsAt;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsDateString extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = false;
        if (is_string($value) && preg_match('/^\\d{4}-\\d{2}-\\d{2}([T ].*)?$/', $value)) {
            try {
                $ok = new \DateTimeImmutable($value) instanceof \DateTimeImmutable;
            } catch (\Throwable) {
                $ok = false;
            }
        }

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must be a valid ISO 8601 date string", $value);
    }
}
