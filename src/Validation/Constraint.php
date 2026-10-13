<?php

declare(strict_types=1);

namespace Nesto\Validation;

/**
 * Base class of every validation attribute in Nesto\Validation\Attributes.
 *
 *   #[IsString(message: 'give me a string')]   // custom message; $property, $value, $constraint1… are replaced
 *   #[IsInt(each: true)]                       // apply to every element of an array
 *   #[MinLength(3, groups: ['create'])]        // only when validating with ['groups' => ['create']]
 *   #[IsEmail(always: true)]                   // regardless of groups
 *
 * To write your own attribute extend this class, mark it #[Attribute(Attribute::TARGET_PROPERTY)]
 * and return an error message (or null) from validate().
 */
abstract class Constraint
{
    /** @param list<string> $groups */
    public function __construct(
        public readonly ?string $message = null,
        public readonly array $groups = [],
        public readonly bool $always = false,
        public readonly bool $each = false,
    ) {}

    /** @return string|null error message, or null when valid */
    abstract public function validate(mixed $value, ValidationContext $ctx): ?string;

    /** Key used in ValidationError::$constraints — "isString", "minLength", … */
    public function name(): string
    {
        $parts = explode('\\', static::class);

        return lcfirst(end($parts));
    }

    /**
     * Builds the error message: the custom `message` with placeholders replaced, or the default.
     *
     * @param list<mixed> $constraints values for $constraint1, $constraint2, …
     */
    protected function fail(ValidationContext $ctx, string $default, mixed $value = null, array $constraints = []): string
    {
        if ($this->message === null) {
            return $default;
        }
        $replace = [
            '$property' => $ctx->property,
            '$target' => $ctx->targetName(),
            '$value' => self::stringify($value),
        ];
        foreach (array_values($constraints) as $i => $c) {
            $replace['$constraint' . ($i + 1)] = self::stringify($c);
        }

        return strtr($this->message, $replace);
    }

    /** Human-readable rendering of a value for messages. */
    protected static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            is_array($value) => implode(', ', array_map(static fn ($v) => self::stringify($v), $value)),
            is_object($value) => $value::class,
            default => gettype($value),
        };
    }

    /** "undefined" / null / '' → empty, like class-validator's isNotEmpty. */
    protected static function isEmptyValue(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
