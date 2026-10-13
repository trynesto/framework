<?php

declare(strict_types=1);

namespace Nesto\Validation\Contracts;

/**
 * Custom validator used through #[Validate(MyValidator::class)] — the escape hatch for rules
 * that are not covered by the built-in attributes.
 *
 *   final class IsSlug implements ConstraintValidator
 *   {
 *       public function validate(mixed $value, object $object, string $property): ?string
 *       {
 *           return is_string($value) && preg_match('/^[a-z0-9-]+$/', $value) ? null : "$property must be a slug";
 *       }
 *   }
 *
 *   #[Validate(IsSlug::class)]
 *   public string $slug;
 */
interface ConstraintValidator
{
    /** @return string|null the error message, or null when the value is valid */
    public function validate(mixed $value, object $object, string $property): ?string;
}
