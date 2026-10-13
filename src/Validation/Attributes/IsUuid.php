<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Value must be a UUID (any version by default, or a specific one).
 *
 *   #[IsUuid(version: 4)]
 *   public string $id;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsUuid extends Constraint
{
    /**
     * @param int|'all' $version
     * @param list<string> $groups
     */
    public function __construct(
        public readonly int|string $version = 'all',
        ?string $message = null,
        array $groups = [],
        bool $always = false,
        bool $each = false,
    ) {
        parent::__construct($message, $groups, $always, $each);
    }

    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $v = (string) $this->version;
        $pattern = match ($v) {
            'all' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            default => '/^[0-9a-f]{8}-[0-9a-f]{4}-' . $v . '[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        };
        $ok = is_string($value) && preg_match($pattern, $value) === 1;

        return $ok ? null : $this->fail($ctx, "{$ctx->property} must be a UUID", $value);
    }
}
