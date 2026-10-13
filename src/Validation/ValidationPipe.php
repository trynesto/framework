<?php

declare(strict_types=1);

namespace Nesto\Validation;

use Nesto\Common\ArgumentMetadata;
use Nesto\Common\Contracts\Pipe;
use Nesto\Common\Exceptions\ValidationException;
use Nesto\Transformer\ClassMetadata;
use Nesto\Transformer\ClassTransformer;

/**
 * Nest's ValidationPipe: hydrates DTOs with ClassTransformer, validates them with Validator and throws a
 * 400 ValidationException ({ statusCode, message, errors: { field: [...] }, error }) on failure.
 *
 *   $app->useGlobalPipes(new ValidationPipe(whitelist: true, forbidNonWhitelisted: true));
 *   $app->useGlobalPipes(new ValidationPipe(['transform' => true, 'groups' => ['create']]));
 *
 *   #[Post]
 *   public function store(#[Body] CreateCatDto $dto) {}          // array → CreateCatDto, validated
 *   #[Get(':id')]
 *   public function show(#[Param('id')] int $id) {}             // "abc" → 400 "Validation failed (numeric string is expected)"
 *
 * Options (array keys or named constructor arguments; named arguments win):
 *   whitelist              strip keys that do not match a DTO property
 *   forbidNonWhitelisted   400 "property x should not exist" instead of stripping
 *   transform              return the hydrated DTO (default) and cast primitives for #[Param] / #[Query]
 *   skipMissingProperties  passed to Validator
 *   disableErrorMessages   throw with an empty message list
 *   validateCustomDecorators also validate values produced by custom ParamResolver attributes
 *   groups, stopAtFirstError, always  passed to Validator
 *   exceptionFactory       callable(list<ValidationError>): \Throwable
 *   errorHttpStatusCode    400 (default) or e.g. 422, for every validation error of the app
 *   transformOptions       passed to ClassTransformer::plainToInstance()
 */
final class ValidationPipe implements Pipe
{
    public readonly bool $whitelist;
    public readonly bool $forbidNonWhitelisted;
    public readonly bool $transform;
    public readonly bool $skipMissingProperties;
    public readonly bool $disableErrorMessages;
    public readonly bool $validateCustomDecorators;
    public readonly bool $stopAtFirstError;
    public readonly bool $always;
    /** @var list<string> */
    public readonly array $groups;
    /** @var array<string, mixed> */
    public readonly array $transformOptions;
    /** @var (callable(list<ValidationError>): \Throwable)|null */
    private $exceptionFactory;

    private readonly Validator $validator;

    /**
     * @param array<string, mixed> $options
     * @param list<string>|null    $groups
     * @param (callable(list<ValidationError>): \Throwable)|null $exceptionFactory
     */
    public function __construct(
        array $options = [],
        ?bool $whitelist = null,
        ?bool $forbidNonWhitelisted = null,
        ?bool $transform = null,
        ?bool $skipMissingProperties = null,
        ?bool $disableErrorMessages = null,
        ?bool $validateCustomDecorators = null,
        ?array $groups = null,
        ?callable $exceptionFactory = null,
        ?bool $stopAtFirstError = null,
        ?array $transformOptions = null,
        ?bool $always = null,
        ?Validator $validator = null,
        ?int $errorHttpStatusCode = null,
    ) {
        // one status for every validation failure of the app (DTOs, files, params): 400 (Nest) or 422 (Laravel)
        $status = $errorHttpStatusCode ?? ($options['errorHttpStatusCode'] ?? null);
        if ($status !== null) {
            ValidationException::$defaultStatus = (int) $status;
        }

        $this->whitelist = $whitelist ?? (bool) ($options['whitelist'] ?? false);
        $this->forbidNonWhitelisted = $forbidNonWhitelisted ?? (bool) ($options['forbidNonWhitelisted'] ?? false);
        $this->transform = $transform ?? (bool) ($options['transform'] ?? true);
        $this->skipMissingProperties = $skipMissingProperties ?? (bool) ($options['skipMissingProperties'] ?? false);
        $this->disableErrorMessages = $disableErrorMessages ?? (bool) ($options['disableErrorMessages'] ?? false);
        $this->validateCustomDecorators = $validateCustomDecorators ?? (bool) ($options['validateCustomDecorators'] ?? false);
        $this->stopAtFirstError = $stopAtFirstError ?? (bool) ($options['stopAtFirstError'] ?? false);
        $this->always = $always ?? (bool) ($options['always'] ?? false);
        $this->groups = $groups ?? ($options['groups'] ?? []);
        $this->transformOptions = $transformOptions ?? ($options['transformOptions'] ?? []);
        $this->exceptionFactory = $exceptionFactory ?? ($options['exceptionFactory'] ?? null);
        $this->validator = $validator ?? new Validator;
    }

    public function transform(mixed $value, ArgumentMetadata $metadata): mixed
    {
        $metatype = $metadata->metatype;
        if (! $this->toValidate($metadata)) {
            return $this->transform ? $this->transformPrimitive($value, $metadata) : $value;
        }
        /** @var class-string $metatype */
        if ($value === null) {
            return null;                                    // RouteHandler decides: default / nullable / 400 missing
        }

        if (is_array($value)) {
            if ($this->whitelist) {
                $unknown = $this->unknownKeys($metatype, $value, '');
                if ($this->forbidNonWhitelisted && $unknown !== []) {
                    throw $this->exception([], array_combine($unknown, array_map(static fn ($k) => ["property $k should not exist"], $unknown)));
                }
                $value = $this->strip($metatype, $value);
            }
            $entity = ClassTransformer::plainToInstance($metatype, $value, $this->transformOptions);
        } elseif ($value instanceof $metatype) {
            $entity = $value;
        } else {
            return $value;                                  // scalar for a class type: let RouteHandler::coerce() report it
        }

        $errors = $this->validator->validate($entity, [
            'groups' => $this->groups,
            'skipMissingProperties' => $this->skipMissingProperties,
            'stopAtFirstError' => $this->stopAtFirstError,
            'always' => $this->always,
        ]);
        if ($errors !== []) {
            throw $this->exception($errors, Validator::toMap($errors));
        }

        return $this->transform ? $entity : $value;
    }

    // ---- helpers -------------------------------------------------------------------------

    private function toValidate(ArgumentMetadata $metadata): bool
    {
        if ($metadata->type === 'custom' && ! $this->validateCustomDecorators) {
            return false;
        }
        if ($metadata->type === 'file' || $metadata->type === 'files') {
            return false; // uploads are checked by ParseFilePipe, not class-validator rules
        }
        $type = $metadata->metatype;
        if ($type === null || $type === '') {
            return false;
        }
        $type = ltrim($type, '\\');
        if (in_array(strtolower($type), ['int', 'integer', 'float', 'double', 'bool', 'boolean', 'string', 'array', 'mixed', 'object', 'iterable', 'callable', 'null', 'true', 'false', 'void', 'never'], true)) {
            return false;
        }
        if (! class_exists($type) || enum_exists($type) || is_a($type, \DateTimeInterface::class, true)) {
            return false;
        }

        return (new \ReflectionClass($type))->isInstantiable();
    }

    private function transformPrimitive(mixed $value, ArgumentMetadata $metadata): mixed
    {
        if (! in_array($metadata->type, ['param', 'query'], true) || $value === null || ! is_scalar($value)) {
            return $value;
        }
        $field = $metadata->data ?? $metadata->type;
        switch (strtolower((string) $metadata->metatype)) {
            case 'int':
            case 'integer':
                if (is_int($value)) {
                    return $value;
                }
                if (is_string($value) && preg_match('/^\s*-?\d+\s*$/', $value)) {
                    return (int) trim($value);
                }
                throw ValidationException::field($field, "$field must be an integer");
            case 'float':
            case 'double':
                if (is_int($value) || is_float($value)) {
                    return (float) $value;
                }
                if (is_string($value) && is_numeric(trim($value))) {
                    return (float) trim($value);
                }
                throw ValidationException::field($field, "$field must be a number");
            case 'bool':
            case 'boolean':
                if (is_bool($value)) {
                    return $value;
                }
                $b = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($b === null) {
                    throw ValidationException::field($field, "$field must be a boolean value");
                }

                return $b;
            case 'string':
                return is_string($value) ? $value : (string) $value;
            default:
                return $value;
        }
    }

    /**
     * Unknown keys at every nesting level: "extra", "address.street2", "items.0.foo".
     * @param class-string $class
     * @return list<string>
     */
    private function unknownKeys(string $class, array $plain, string $prefix): array
    {
        $naming = $this->transformOptions['namingStrategy'] ?? null;
        $unknown = array_map(static fn ($k) => $prefix . $k, ClassTransformer::unknownKeys($class, $plain, $this->transformOptions));
        $meta = ClassMetadata::of($class);
        foreach ($plain as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            $prop = $meta->propertyForKey((string) $key, $naming);
            if ($prop === null) {
                continue;
            }
            $nested = $prop->type?->type ?? $prop->classType();
            if ($nested === null || ! class_exists($nested) || enum_exists($nested) || is_a($nested, \DateTimeInterface::class, true)) {
                continue;
            }
            if (array_is_list($value) && $value !== [] && ($prop->type !== null || ! $prop->classType())) {
                foreach ($value as $i => $item) {
                    if (is_array($item)) {
                        $unknown = [...$unknown, ...$this->unknownKeys($nested, $item, "$prefix$key.$i.")];
                    }
                }
            } elseif (! array_is_list($value) || $value === []) {
                $unknown = [...$unknown, ...$this->unknownKeys($nested, $value, "$prefix$key.")];
            }
        }

        return $unknown;
    }

    /** @param class-string $class */
    private function strip(string $class, array $plain): array
    {
        foreach (ClassTransformer::unknownKeys($class, $plain, $this->transformOptions) as $key) {
            unset($plain[$key]);
        }

        return $plain;
    }

    /**
     * @param list<ValidationError>         $errors
     * @param array<string, list<string>>   $map field => messages
     */
    private function exception(array $errors, array $map): \Throwable
    {
        if ($this->exceptionFactory !== null) {
            return ($this->exceptionFactory)($errors, $map === [] ? [] : array_merge(...array_values($map)));
        }

        return new ValidationException($this->disableErrorMessages ? [] : $map);
    }
}
