<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Value must be a DateTimeInterface instance (use the transformer to turn strings into dates first).
 *
 *   #[IsDate]
 *   public \DateTimeImmutable $bornAt;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsDate extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        return $value instanceof \DateTimeInterface ? null : $this->fail($ctx, "{$ctx->property} must be a Date instance", $value);
    }
}
