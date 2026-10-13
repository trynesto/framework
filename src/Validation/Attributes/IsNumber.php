<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Value must be an int or float (NaN / INF rejected unless allowed, optional max decimal places).
 *
 *   #[IsNumber(maxDecimalPlaces: 2)]
 *   public float $price;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsNumber extends Constraint
{
    /**
     * @param list<string> $groups
     */
    public function __construct(
        public readonly bool $allowNaN = false,
        public readonly bool $allowInfinity = false,
        public readonly ?int $maxDecimalPlaces = null,
        ?string $message = null,
        array $groups = [],
        bool $always = false,
        bool $each = false,
    ) {
        parent::__construct($message, $groups, $always, $each);
    }

    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = is_int($value) || is_float($value);
        if ($ok && is_float($value)) {
            $ok = ($this->allowNaN || ! is_nan($value)) && ($this->allowInfinity || is_finite($value));
            if ($ok && $this->maxDecimalPlaces !== null) {
                $ok = round($value, $this->maxDecimalPlaces) == $value;
            }
        }

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must be a number conforming to the specified constraints", $value);
    }
}
