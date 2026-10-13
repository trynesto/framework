<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * String must start with the prefix.
 *
 *   #[StartsWith('sk_')]
 *   public string $key;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class StartsWith extends Constraint
{
    /**
     * @param list<string> $groups
     */
    public function __construct(
        public readonly string $prefix,
        ?string $message = null,
        array $groups = [],
        bool $always = false,
        bool $each = false,
    ) {
        parent::__construct($message, $groups, $always, $each);
    }

    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = is_string($value) && str_starts_with($value, $this->prefix);

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must start with {$this->prefix}", $value, [$this->prefix]);
    }
}
