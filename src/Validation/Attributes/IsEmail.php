<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Value must be an e-mail address.
 *
 *   #[IsEmail]
 *   public string $email;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsEmail extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must be an email", $value);
    }
}
