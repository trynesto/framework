<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Array must contain at least min elements.
 *
 *   #[ArrayMinSize(1)]
 *   public array $items;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ArrayMinSize extends Constraint
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
        $ok = is_array($value) && count($value) >= $this->min;

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must contain at least {$this->min} elements", $value, [$this->min]);
    }
}
