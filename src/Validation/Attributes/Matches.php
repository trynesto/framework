<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * String must match the given PCRE pattern.
 *
 *   #[Matches('/^[a-z0-9-]+$/')]
 *   public string $slug;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Matches extends Constraint
{
    /**
     * @param list<string> $groups
     */
    public function __construct(
        public readonly string $pattern,
        ?string $message = null,
        array $groups = [],
        bool $always = false,
        bool $each = false,
    ) {
        parent::__construct($message, $groups, $always, $each);
    }

    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = is_string($value) && preg_match($this->pattern, $value) === 1;

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must match {$this->pattern} regular expression", $value, [$this->pattern]);
    }
}
