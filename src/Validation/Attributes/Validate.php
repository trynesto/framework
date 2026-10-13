<?php

declare(strict_types=1);

namespace Nesto\Validation\Attributes;

use Attribute;
use Nesto\Validation\Constraint;
use Nesto\Validation\Contracts\ConstraintValidator;
use Nesto\Validation\ValidationContext;
use Nesto\Validation\Validator;

/**
 * Runs a custom ConstraintValidator — the escape hatch for anything the built-in attributes do not cover.
 *
 *   #[Validate(IsSlugValidator::class)]
 *   public string $slug;
 *
 *   #[Validate(new InRange(1, 10))]          // instances are allowed (PHP 8.1 "new in initializers")
 *   public int $level;
 *
 * The error key in ValidationError::$constraints is the validator's short class name in lowerCamelCase.
 * Validator classes are built with Validator::getFromContainer(): call Validator::useContainer($app) once
 * and they get constructor injection (repositories, services) like any provider.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Validate extends Constraint
{
    /**
     * @param class-string<ConstraintValidator>|ConstraintValidator $validator
     * @param list<string> $groups
     */
    public function __construct(
        public readonly string|ConstraintValidator $validator,
        ?string $message = null,
        array $groups = [],
        bool $always = false,
        bool $each = false,
    ) {
        parent::__construct($message, $groups, $always, $each);
    }

    public function validate(mixed $value, ValidationContext $ctx): ?string
    {
        $error = $this->instance()->validate($value, $ctx->object, $ctx->property);
        if ($error === null) {
            return null;
        }

        return $this->fail($ctx, $error, $value);
    }

    public function name(): string
    {
        $parts = explode('\\', is_object($this->validator) ? $this->validator::class : $this->validator);

        return lcfirst(end($parts));
    }

    private function instance(): ConstraintValidator
    {
        if ($this->validator instanceof ConstraintValidator) {
            return $this->validator;
        }
        if (! is_subclass_of($this->validator, ConstraintValidator::class)) {
            throw new \LogicException(sprintf('#[Validate]: %s must implement %s.', $this->validator, ConstraintValidator::class));
        }

        return Validator::getFromContainer($this->validator);
    }
}
