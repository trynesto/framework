<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Loose E.164 phone number: "+" followed by 7–15 digits (not starting with 0), or a 7–15 digit national number; spaces, dots, dashes and parentheses are ignored.
 *
 *   #[IsPhoneNumber]
 *   public string $phone;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsPhoneNumber extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = false;
        if (is_string($value)) {
            $digits = preg_replace('/[\\s().-]/', '', $value) ?? '';
            $ok = preg_match('/^(\\+[1-9]\\d{6,14}|\\d{7,15})$/', $digits) === 1;
        }

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must be a valid phone number", $value);
    }
}
