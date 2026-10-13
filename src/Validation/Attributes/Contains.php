<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * String must contain the seed.
 *
 *   #[Contains('@')]
 *   public string $handle;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Contains extends Constraint
{
    /**
     * @param list<string> $groups
     */
    public function __construct(
        public readonly string $seed,
        ?string $message = null,
        array $groups = [],
        bool $always = false,
        bool $each = false,
    ) {
        parent::__construct($message, $groups, $always, $each);
    }

    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = is_string($value) && str_contains($value, $this->seed);

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must contain a {$this->seed} string", $value, [$this->seed]);
    }
}
