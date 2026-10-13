<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Value must not be one of the given values (strict comparison).
 *
 *   #[IsNotIn(['admin', 'root'])]
 *   public string $username;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsNotIn extends Constraint
{
    /**
     * @param list<mixed> $values
     * @param list<string> $groups
     */
    public function __construct(
        public readonly array $values,
        ?string $message = null,
        array $groups = [],
        bool $always = false,
        bool $each = false,
    ) {
        parent::__construct($message, $groups, $always, $each);
    }

    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        return in_array($value, $this->values, true) ? $this->fail($ctx, "{$ctx->property} should not be one of the following values: " . self::stringify($this->values), $value, [$this->values]) : null;
    }
}
