<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Numeric value must be <= max.
 *
 *   #[Max(120)]
 *   public int $age;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Max extends Constraint
{
    /**
     * @param list<string> $groups
     */
    public function __construct(
        public readonly int|float $max,
        ?string $message = null,
        array $groups = [],
        bool $always = false,
        bool $each = false,
    ) {
        parent::__construct($message, $groups, $always, $each);
    }

    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = (is_int($value) || is_float($value)) && $value <= $this->max;

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must not be greater than {$this->max}", $value, [$this->max]);
    }
}
