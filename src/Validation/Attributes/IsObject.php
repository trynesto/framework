<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Value must be an object (a hydrated nested DTO, or an associative array coming from a JSON object).
 *
 *   #[IsObject]
 *   public array $meta;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsObject extends Constraint
{
    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = is_object($value) || (is_array($value) && ($value === [] || ! array_is_list($value)));

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must be an object", $value);
    }
}
