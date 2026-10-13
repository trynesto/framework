<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Value must be a case of the given enum (the instance itself, its backing value, or its name).
 *
 *   #[IsEnum(Status::class)]
 *   public Status $status;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IsEnum extends Constraint
{
    /**
     * @param class-string<\UnitEnum> $enum
     * @param list<string> $groups
     */
    public function __construct(
        public readonly string $enum,
        ?string $message = null,
        array $groups = [],
        bool $always = false,
        bool $each = false,
    ) {
        parent::__construct($message, $groups, $always, $each);
    }

    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        if ($value instanceof $this->enum) {
            return null;
        }
        $allowed = [];
        foreach ($this->enum::cases() as $case) {
            $allowed[] = $case instanceof \BackedEnum ? $case->value : $case->name;
        }
        if (is_int($value) || is_string($value)) {
            foreach ($allowed as $a) {
                if ($a === $value || (string) $a === (string) $value) {
                    return null;
                }
            }
        }

        return $this->fail($ctx, "{$ctx->property} must be one of the following values: " . implode(', ', $allowed), $value, [$this->enum, $allowed]);
    }
}
