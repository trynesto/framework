<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * String length (in characters) must be >= min.
 *
 *   #[MinLength(8)]
 *   public string $password;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class MinLength extends Constraint
{
    /**
     * @param list<string> $groups
     */
    public function __construct(
        public readonly int $min,
        ?string $message = null,
        array $groups = [],
        bool $always = false,
        bool $each = false,
    ) {
        parent::__construct($message, $groups, $always, $each);
    }

    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = is_string($value) && mb_strlen($value) >= $this->min;

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must be longer than or equal to {$this->min} characters", $value, [$this->min]);
    }
}
