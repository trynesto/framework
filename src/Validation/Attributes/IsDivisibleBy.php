<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Numeric value must be divisible by num.
 *
 *   #[IsDivisibleBy(5)]
 *   public int $step;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsDivisibleBy extends Constraint
{
    /**
     * @param list<string> $groups
     */
    public function __construct(
        public readonly int|float $num,
        ?string $message = null,
        array $groups = [],
        bool $always = false,
        bool $each = false,
    ) {
        parent::__construct($message, $groups, $always, $each);
    }

    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = (is_int($value) || is_float($value)) && $this->num != 0 && fmod((float) $value, (float) $this->num) == 0.0;

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must be divisible by {$this->num}", $value, [$this->num]);
    }
}
