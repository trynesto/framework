<?php

declare(strict_types=1);

namespace Nesto\Transformer;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use Traversable;
use UnitEnum;
use WeakMap;

/**
 * class-transformer for PHP: plain arrays ↔ typed objects, driven by property types and
 * the attributes in Nesto\Transformer\Attributes (#[Type], #[Expose], #[Exclude], #[Transform]).
 *
 *   $dto  = ClassTransformer::plainToInstance(CreateCatDto::class, $request->body());
 *   $dtos = ClassTransformer::plainToInstances(CreateCatDto::class, $request->body()['cats']);
 *   $json = ClassTransformer::instanceToPlain($cat, ['groups' => ['admin']]);
 *
 * Options (both directions unless noted):
 *   groups                  list<string>   only properties #[Expose(groups: …)]d in one of these (plus un-grouped ones)
 *   excludeExtraneousValues bool           only #[Expose]d properties are touched
 *   namingStrategy          'snake'|'camel' plain keys are snake_case / camelCase versions of property names
 *
 * Hydration casts by declared property type: int/float/bool/string (query strings "1", "true" are accepted),
 * nullable, BackedEnum (from value), UnitEnum (from name), DateTime/DateTimeImmutable (from string or timestamp),
 * nested objects (recursively) and arrays of objects via #[Type(Item::class)]. A value that cannot be assigned
 * (e.g. "abc" into an int property) leaves the property uninitialized and is kept in rawValues() so the
 * validator can still report it. Unknown keys never create dynamic properties.
 */
final class ClassTransformer
{
    /** @var WeakMap<object, array<string, mixed>>|null plain values that could not be assigned to a typed property */
    private static ?WeakMap $raw = null;

    private function __construct() {}

    // ---- plain → class ---------------------------------------------------------------

    /**
     * @template T of object
     * @param class-string<T>      $class
     * @param array<string, mixed> $plain
     * @param array<string, mixed> $options
     * @return T
     */
    public static function plainToInstance(string $class, array $plain, array $options = []): object
    {
        if (! class_exists($class) || enum_exists($class)) {
            throw new \InvalidArgumentException(sprintf('ClassTransformer::plainToInstance(): "%s" is not an instantiable class.', $class));
        }
        $meta = ClassMetadata::of($class);
        $naming = $options['namingStrategy'] ?? null;

        // 1. collect + cast values for every hydratable property
        /** @var array<string, array{0: bool, 1: mixed}> $values property → [assignable, value] */
        $values = [];
        foreach ($meta->properties as $prop) {
            if (! self::hydratable($prop, $options)) {
                continue;
            }
            [$found, $value] = self::lookup($plain, $prop, $naming);
            if (! $found) {
                continue;
            }
            $values[$prop->name] = self::cast($value, $prop, $plain, $options);
        }

        // 2. instantiate — through the constructor when every required parameter can be satisfied
        $instance = null;
        $passed = [];
        if ($meta->constructorParams !== []) {
            $args = [];
            $satisfiable = true;
            foreach ($meta->constructorParams as $param) {
                $n = $param->getName();
                if ($param->isPromoted() && isset($values[$n]) && $values[$n][0]) {
                    $args[$n] = $values[$n][1];
                    $passed[$n] = true;
                } elseif ($param->isDefaultValueAvailable()) {
                    continue;
                } elseif ($param->allowsNull()) {
                    $args[$n] = null;
                    $passed[$n] = $param->isPromoted();
                } else {
                    $satisfiable = false;
                    break;
                }
            }
            if ($satisfiable) {
                $instance = $meta->reflection->newInstanceArgs($args);
            }
        } else {
            $instance = $meta->reflection->newInstance();
        }
        if ($instance === null) {
            $instance = $meta->reflection->newInstanceWithoutConstructor();
            // promoted params with defaults would normally be initialised by the constructor
            foreach ($meta->constructorParams as $param) {
                $n = $param->getName();
                if ($param->isPromoted() && $param->isDefaultValueAvailable() && isset($meta->properties[$n]) && (! isset($values[$n]) || ! $values[$n][0])) {
                    $meta->properties[$n]->reflection->setValue($instance, $param->getDefaultValue());
                }
            }
        }

        // 3. assign the rest
        foreach ($values as $name => [$ok, $value]) {
            if (isset($passed[$name])) {
                continue;
            }
            if ($ok) {
                $meta->properties[$name]->reflection->setValue($instance, $value);
            } else {
                self::rememberRaw($instance, $name, $value);
            }
        }

        return $instance;
    }

    /**
     * @template T of object
     * @param class-string<T>            $class
     * @param list<array<string, mixed>> $list
     * @return list<T>
     */
    public static function plainToInstances(string $class, array $list, array $options = []): array
    {
        return array_map(
            static fn ($item) => is_array($item) ? self::plainToInstance($class, $item, $options) : $item,
            array_values($list)
        );
    }

    /**
     * Keys of $plain that do not map to any public property of $class (Nest's "non-whitelisted" properties).
     *
     * @param class-string         $class
     * @param array<string, mixed> $plain
     * @return list<string>
     */
    public static function unknownKeys(string $class, array $plain, array $options = []): array
    {
        $meta = ClassMetadata::of($class);
        $naming = $options['namingStrategy'] ?? null;
        $unknown = [];
        foreach (array_keys($plain) as $key) {
            $prop = $meta->propertyForKey((string) $key, $naming);
            if ($prop === null || ! self::hydratable($prop, $options)) {
                $unknown[] = (string) $key;
            }
        }

        return $unknown;
    }

    /**
     * Plain values that could not be assigned to a typed property during plainToInstance()
     * (the property was left uninitialized instead). property name → original value.
     *
     * @return array<string, mixed>
     */
    public static function rawValues(object $instance): array
    {
        return self::$raw?->offsetExists($instance) ? self::$raw[$instance] : [];
    }

    // ---- class → plain ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function instanceToPlain(object $instance, array $options = []): array
    {
        if ($instance instanceof \Nesto\Orm\Pagination) {
            return self::toPlain($instance, $options);
        }
        if ($instance instanceof JsonSerializable) {
            $plain = self::toPlain($instance->jsonSerialize(), $options);

            return is_array($plain) ? $plain : (array) $plain;
        }
        if ($instance instanceof DateTimeInterface || $instance instanceof UnitEnum) {
            return (array) self::toPlain($instance, $options);
        }
        if ($instance instanceof \stdClass) {
            return array_map(static fn ($v) => self::toPlain($v, $options), get_object_vars($instance));
        }
        if ($instance instanceof Traversable) {
            return array_map(static fn ($v) => self::toPlain($v, $options), iterator_to_array($instance));
        }
        if ($instance instanceof \Closure) {
            return [];
        }

        $meta = ClassMetadata::of($instance::class);
        $naming = $options['namingStrategy'] ?? null;
        $out = [];
        foreach ($meta->properties as $prop) {
            if ($prop->isExcludedToPlain() || ! self::inGroups($prop, $options) || ! self::exposedEnough($prop, $options)) {
                continue;
            }
            if (! $prop->reflection->isInitialized($instance)) {
                continue;
            }
            $value = $prop->reflection->getValue($instance);
            if ($prop->transform !== null && ! $prop->transform->toClassOnly) {
                $value = ($prop->transform->resolve())($value, $instance, $prop->name);
            }
            $out[$prop->plainKey($naming)] = self::toPlain($value, $options);
        }
        foreach ($meta->exposedMethods as ['method' => $method, 'expose' => $expose, 'key' => $key]) {
            if ($expose->toClassOnly || ! self::groupsMatch($expose->groups, $options)) {
                continue;
            }
            if ($expose->name === null && $naming === 'snake') {
                $key = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
            }
            $out[$key] = self::toPlain($method->invoke($instance), $options);
        }

        return $out;
    }

    /**
     * Recursively converts anything (objects, lists, maps, enums, dates) into JSON-ready plain data.
     * Used by ClassSerializerInterceptor for handler results such as lists or paginated arrays.
     */
    public static function toPlain(mixed $value, array $options = []): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if (is_array($value)) {
            return array_map(static fn ($v) => self::toPlain($v, $options), $value);
        }
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof UnitEnum) {
            return $value->name;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        if ($value instanceof \Nesto\Orm\Pagination) {
            // serialize the page's entities with the same options (groups...) as the rest of the response
            return ['data' => self::toPlain($value->data, $options), 'meta' => $value->meta()];
        }
        if ($value instanceof JsonSerializable) {
            return self::toPlain($value->jsonSerialize(), $options);
        }
        if ($value instanceof Traversable) {
            return array_map(static fn ($v) => self::toPlain($v, $options), iterator_to_array($value));
        }
        if (is_object($value)) {
            return self::instanceToPlain($value, $options);
        }

        return $value;
    }

    // ---- internals -------------------------------------------------------------------

    private static function hydratable(PropertyMetadata $prop, array $options): bool
    {
        return ! $prop->isExcludedToClass() && self::inGroups($prop, $options) && self::exposedEnough($prop, $options);
    }

    private static function inGroups(PropertyMetadata $prop, array $options): bool
    {
        return self::groupsMatch($prop->groups(), $options);
    }

    /** @param list<string> $own */
    private static function groupsMatch(array $own, array $options): bool
    {
        $wanted = $options['groups'] ?? [];
        if ($wanted === []) {
            return $own === [];
        }

        return $own === [] || array_intersect($own, $wanted) !== [];
    }

    private static function exposedEnough(PropertyMetadata $prop, array $options): bool
    {
        return ! ($options['excludeExtraneousValues'] ?? false) || $prop->expose !== null;
    }

    /** @return array{0: bool, 1: mixed} [found, value] */
    private static function lookup(array $plain, PropertyMetadata $prop, ?string $naming): array
    {
        $key = $prop->plainKey($naming);
        if (array_key_exists($key, $plain)) {
            return [true, $plain[$key]];
        }
        if ($prop->expose?->name === null && $naming === null) {
            // lenient: accept snake_case for a camelCase property
            $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $prop->name));
            if ($snake !== $key && array_key_exists($snake, $plain)) {
                return [true, $plain[$snake]];
            }
        } elseif (array_key_exists($prop->name, $plain)) {
            return [true, $plain[$prop->name]];
        }

        return [false, null];
    }

    /** @return array{0: bool, 1: mixed} [assignable, casted value] */
    private static function cast(mixed $value, PropertyMetadata $prop, array $plain, array $options): array
    {
        if ($prop->transform !== null && ! $prop->transform->toPlainOnly) {
            $transformed = ($prop->transform->resolve())($value, $plain, $prop->name);

            return [self::assignable($transformed, $prop), $transformed];
        }

        if ($prop->type !== null) {
            $target = $prop->type->type;
            $isCollection = is_array($value)
                && ($prop->types === [] || $prop->hasType('array') || $prop->hasType('iterable') || $prop->hasType('mixed'))
                && ! in_array(strtolower($target), ['array', 'iterable'], true);
            if ($isCollection) {
                $out = [];
                foreach ($value as $k => $item) {
                    [$ok, $casted] = self::castTo($target, $item, $options);
                    if (! $ok) {
                        return [false, $value];
                    }
                    $out[$k] = $casted;
                }

                return [true, $out];
            }
            [$ok, $casted] = self::castTo($target, $value, $options);
            if ($ok) {
                return self::assignable($casted, $prop) ? [true, $casted] : [false, $value];
            }

            return [false, $value];
        }

        if ($prop->types === []) {
            return [true, $value];
        }
        if ($value === null) {
            return [$prop->nullable, null];
        }
        if (count($prop->types) > 1 && self::assignable($value, $prop)) {
            return [true, $value];                      // union type already satisfied: keep as is
        }
        foreach ($prop->types as $type) {
            [$ok, $casted] = self::castTo($type, $value, $options);
            if ($ok) {
                return [true, $casted];
            }
        }

        return [false, $value];
    }

    /** Whether a value produced by #[Type] can actually be stored in the declared property type. */
    private static function assignable(mixed $value, PropertyMetadata $prop): bool
    {
        if ($prop->types === []) {
            return true;
        }
        if ($value === null) {
            return $prop->nullable;
        }
        foreach ($prop->types as $t) {
            $ok = match ($t) {
                'mixed' => true,
                'int' => is_int($value),
                'float' => is_float($value) || is_int($value),
                'bool' => is_bool($value),
                'string' => is_string($value),
                'array' => is_array($value),
                'iterable' => is_iterable($value),
                'object' => is_object($value),
                default => $value instanceof $t,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /**
     * Try to convert $value to the given builtin name or class.
     * @return array{0: bool, 1: mixed}
     */
    private static function castTo(string $type, mixed $value, array $options): array
    {
        $type = ltrim($type, '\\');
        switch (strtolower($type)) {
            case 'mixed':
                return [true, $value];
            case 'int':
            case 'integer':
                if (is_int($value)) {
                    return [true, $value];
                }
                if (is_float($value) && floor($value) === $value && is_finite($value)) {
                    return [true, (int) $value];
                }
                if (is_string($value) && preg_match('/^\s*[+-]?\d+\s*$/', $value)) {
                    return [true, (int) trim($value)];
                }

                return [false, $value];
            case 'float':
            case 'double':
                if (is_float($value) || is_int($value)) {
                    return [true, (float) $value];
                }
                if (is_string($value) && is_numeric(trim($value))) {
                    return [true, (float) trim($value)];
                }

                return [false, $value];
            case 'bool':
            case 'boolean':
                if (is_bool($value)) {
                    return [true, $value];
                }
                if (is_int($value) && ($value === 0 || $value === 1)) {
                    return [true, $value === 1];
                }
                if (is_string($value)) {
                    $b = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($b !== null) {
                        return [true, $b];
                    }
                    if ($value === '') {
                        return [true, false];
                    }
                }

                return [false, $value];
            case 'string':
                if (is_string($value)) {
                    return [true, $value];
                }
                if ($value instanceof \Stringable) {
                    return [true, (string) $value];
                }

                return [false, $value];
            case 'array':
                if (is_array($value)) {
                    return [true, $value];
                }
                if ($value instanceof Traversable) {
                    return [true, iterator_to_array($value)];
                }
                if ($value instanceof \Nesto\Http\File) {
                    return [true, [$value]]; // one upload sent as `images` instead of `images[]`
                }

                return [false, $value];
            case 'iterable':
                return [is_iterable($value), $value];
            case 'object':
                if (is_object($value)) {
                    return [true, $value];
                }

                return is_array($value) ? [true, (object) $value] : [false, $value];
            case 'null':
            case 'false':
            case 'true':
                return [false, $value];
        }

        // enums
        if (enum_exists($type)) {
            if ($value instanceof $type) {
                return [true, $value];
            }
            if (is_subclass_of($type, BackedEnum::class)) {
                if (is_int($value) || is_string($value)) {
                    $backing = (string) (new \ReflectionEnum($type))->getBackingType();
                    if ($backing === 'int' && is_string($value) && preg_match('/^-?\d+$/', $value)) {
                        $value = (int) $value;
                    }
                    if ($backing === 'string' && is_int($value)) {
                        $value = (string) $value;
                    }
                    $case = ($backing === 'int' && ! is_int($value)) || ($backing === 'string' && ! is_string($value))
                        ? null
                        : $type::tryFrom($value);

                    return $case !== null ? [true, $case] : [false, $value];
                }

                return [false, $value];
            }
            if (is_string($value)) {
                foreach ($type::cases() as $case) {
                    if ($case->name === $value) {
                        return [true, $case];
                    }
                }
            }

            return [false, $value];
        }

        // dates
        if ($type === DateTimeInterface::class || is_subclass_of($type, DateTimeInterface::class)) {
            $concrete = $type === DateTimeInterface::class ? DateTimeImmutable::class : $type;
            if ($value instanceof $type) {
                return [true, $value];
            }
            if ($value instanceof DateTimeInterface) {
                return [true, $concrete === DateTime::class ? DateTime::createFromInterface($value) : DateTimeImmutable::createFromInterface($value)];
            }
            try {
                if (is_int($value)) {
                    return [true, new $concrete('@' . $value)];
                }
                if (is_string($value) && trim($value) !== '') {
                    return [true, new $concrete($value)];
                }
            } catch (\Throwable) {
            }

            return [false, $value];
        }

        // nested objects
        if (class_exists($type) || interface_exists($type)) {
            if ($value instanceof $type) {
                return [true, $value];
            }
            if (is_array($value) && class_exists($type) && (new \ReflectionClass($type))->isInstantiable()) {
                return [true, self::plainToInstance($type, $value, $options)];
            }

            return [false, $value];
        }

        return [false, $value];
    }

    private static function rememberRaw(object $instance, string $property, mixed $value): void
    {
        self::$raw ??= new WeakMap;
        $current = self::$raw->offsetExists($instance) ? self::$raw[$instance] : [];
        $current[$property] = $value;
        self::$raw[$instance] = $current;
    }
}
