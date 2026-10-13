<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Array must contain no more than max elements.
 *
 *   #[ArrayMaxSize(10)]
 *   public array $items;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ArrayMaxSize extends Constraint
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
        $ok = is_array($value) && count($value) <= $this->max;

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must contain no more than {$this->max} elements", $value, [$this->max]);
    }
}
