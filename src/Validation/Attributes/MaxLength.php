<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * String length (in characters) must be <= max.
 *
 *   #[MaxLength(255)]
 *   public string $title;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class MaxLength extends Constraint
{
    /**
     * @param list<string> $groups
     */
    public function __construct(
        public readonly int $max,
        ?string $message = null,
        array $groups = [],
        bool $always = false,
        bool $each = false,
    ) {
        parent::__construct($message, $groups, $always, $each);
    }

    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = is_string($value) && mb_strlen($value) <= $this->max;

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must be shorter than or equal to {$this->max} characters", $value, [$this->max]);
    }
}
