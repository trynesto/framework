<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\ValidationContext;

/**
 * Conditional validation: the other constraints on the property only run when the condition returns true.
 * The condition is a static callable tuple or an invokable class, called as `($object, $value): bool`.
 *
 *   #[ValidateIf([self::class, 'hasCompany'])]
 *   #[IsString]
 *   public ?string $vatNumber = null;
 *
 *   public static function hasCompany(self $dto, mixed $value): bool { return $dto->company !== null; }
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ValidateIf extends Constraint
{
    /** @param array{0: class-string, 1: string}|class-string $condition */
    public function __construct(public readonly array|string $condition)
    {
        parent::__construct();
    }

    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        return null;
    }

    /** @internal */
    public function shouldValidate(object $object, mixed $value): bool
    {
        $c = $this->condition;
        if (is_string($c) && class_exists($c) && method_exists($c, '__invoke')) {
            $c = new $c;
        }
        if (! is_callable($c)) {
            throw new \LogicException('#[ValidateIf]: condition must be a static callable tuple or an invokable class.');
        }

        return (bool) $c($object, $value);
    }
}
