<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * String must end with the suffix.
 *
 *   #[EndsWith('.pdf')]
 *   public string $file;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class EndsWith extends Constraint
{
    /**
     * @param list<string> $groups
     */
    public function __construct(
        public readonly string $suffix,
        ?string $message = null,
        array $groups = [],
        bool $always = false,
        bool $each = false,
    ) {
        parent::__construct($message, $groups, $always, $each);
    }

    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $ok = is_string($value) && str_ends_with($value, $this->suffix);

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must end with {$this->suffix}", $value, [$this->suffix]);
    }
}
