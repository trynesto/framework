<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * String length (in characters) must be between min and max.
 *
 *   #[Length(3, 50)]
 *   public string $username;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Length extends Constraint
{
    /**
     * @param list<string> $groups
     */
    public function __construct(
        public readonly int $min,
        public readonly ?int $max = null,
        ?string $message = null,
        array $groups = [],
        bool $always = false,
        bool $each = false,
    ) {
        parent::__construct($message, $groups, $always, $each);
    }

    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        if (! is_string($value) || mb_strlen($value) < $this->min) {
            return $this->fail($ctx, "{$ctx->property} must be longer than or equal to {$this->min} characters", $value, [$this->min, $this->max]);
        }
        if ($this->max !== null && mb_strlen($value) > $this->max) {
            return $this->fail($ctx, "{$ctx->property} must be shorter than or equal to {$this->max} characters", $value, [$this->min, $this->max]);
        }

        return null;
    }
}
