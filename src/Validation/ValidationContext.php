<?php

declare(strict_types=1);

namespace Nesto\Validation;

/**
 * Passed to every Constraint::validate() call: the object being validated, the property name,
 * the validator options and the Validator itself (for nested validation).
 */
final class ValidationContext
{
    /**
     * @param array<string, mixed> $options  options given to Validator::validate()
     * @param bool                 $defined  false when the property was never initialised (JS "undefined")
     * @param string               $path     dotted path from the root object ("" at the root)
     */
    public function __construct(
        public readonly object $object,
        public readonly string $property,
        public readonly array $options,
        public readonly Validator $validator,
        public readonly bool $defined = true,
        public readonly string $path = '',
    ) {}

    public function targetName(): string
    {
        $parts = explode('\\', $this->object::class);

        return end($parts);
    }
}
