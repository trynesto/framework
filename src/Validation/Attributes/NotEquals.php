<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Value must not be identical (===) to the comparison value.
 *
 *   #[NotEquals('password')]
 *   public string $password;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class NotEquals extends Constraint
{
    /**
     * @param list<string> $groups
     */
    public function __construct(
        public readonly mixed $comparison,
        ?string $message = null,
        array $groups = [],
        bool $always = false,
        bool $each = false,
    ) {
        parent::__construct($message, $groups, $always, $each);
    }

    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        return $value === $this->comparison ? $this->fail($ctx, "{$ctx->property} should not be equal to " . self::stringify($this->comparison), $value, [$this->comparison]) : null;
    }
}
