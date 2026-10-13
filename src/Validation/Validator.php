<?php

declare(strict_types=1);

namespace Nesto\Validation;

use Nesto\Transformer\ClassTransformer;
use Nesto\Validation\Attributes\IsDefined;
use Nesto\Validation\Attributes\IsOptional;
use Nesto\Validation\Attributes\ValidateIf;
use Nesto\Validation\Attributes\ValidateNested;
use ReflectionClass;
use ReflectionProperty;

/**
 * class-validator for PHP: validates an object against the Constraint attributes on its public properties.
 *
 *   $errors = (new Validator)->validate($dto, ['groups' => ['create']]);
 *   if ($errors !== []) {
 *       throw new ValidationException(Validator::toMap($errors));   // ["name" => ["name must be a string"], "address.city" => [...]]
 *   }
 *
 * Options:
 *   groups                 list<string>  only constraints in one of these groups (or marked always) run
 *   always                 bool          run constraints that declare groups even when no groups are given
 *   skipMissingProperties  bool          skip null / undefined properties (except #[IsDefined])
 *   skipUndefinedProperties bool         skip undefined (never initialised) properties only
 *   skipNullProperties     bool          skip null properties only
 *   stopAtFirstError       bool          report only the first failing constraint per property
 *
 * Semantics: an uninitialised typed property counts as "undefined" (null for every constraint except
 * #[IsDefined]); #[IsOptional] skips all constraints when the value is null / undefined; values that the
 * ClassTransformer could not assign (e.g. "abc" into an int property) are validated as the original value.
 */
final class Validator
{
    /** @var array<class-string, array<string, list<Constraint>>> class → property → constraints */
    private static array $constraints = [];
    /** Object with a get(string): mixed method (NestoApplication, ModuleRef, PSR-11 container) used to build validators. */
    private static ?object $container = null;
    private static bool $fallbackOnErrors = true;

    /**
     * class-validator's useContainer(): custom ConstraintValidator classes used with #[Validate(Foo::class)]
     * are resolved through the DI container, so they can inject repositories / services.
     *
     *   Validator::useContainer($app, ['fallbackOnErrors' => true]);   // in main / bootstrap
     *
     * The validator class must be a provider somewhere in the module graph (list it in a module's providers).
     * With fallbackOnErrors (default) a class the container cannot build is instantiated with `new`.
     *
     * @param object|null $container anything with get(string $id): mixed — null restores plain `new`
     * @param array{fallbackOnErrors?: bool, fallback?: bool} $options
     */
    public static function useContainer(?object $container, array $options = []): void
    {
        if ($container !== null && ! method_exists($container, 'get')) {
            throw new \InvalidArgumentException('Validator::useContainer() expects an object with a get(string $id) method.');
        }
        self::$container = $container;
        self::$fallbackOnErrors = (bool) ($options['fallbackOnErrors'] ?? $options['fallback'] ?? true);
    }

    /**
     * Instantiate a custom validator class: through the container set with useContainer(), else with `new`.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public static function getFromContainer(string $class): object
    {
        if (self::$container !== null) {
            try {
                $instance = self::$container->get($class);
                if ($instance instanceof $class) {
                    return $instance;
                }
            } catch (\Throwable $e) {
                if (! self::$fallbackOnErrors) {
                    throw $e;
                }
            }
        }

        return new $class;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<ValidationError>
     */
    public function validate(object $object, array $options = []): array
    {
        return $this->validateObject($object, $options, '');
    }

    /**
     * @param list<ValidationError> $errors
     * @return array<string, list<string>> property path => messages
     */
    public static function toMap(array $errors): array
    {
        $map = [];
        foreach ($errors as $error) {
            foreach ($error->toMap() as $field => $messages) {
                $map[$field] = [...($map[$field] ?? []), ...$messages];
            }
        }

        return $map;
    }

    /**
     * Nest-style flat messages, nested paths joined with dots: "address.city must be a string".
     *
     * @param list<ValidationError> $errors
     * @return list<string>
     */
    public static function flatten(array $errors): array
    {
        $out = [];
        foreach ($errors as $error) {
            $out = [...$out, ...$error->messages()];
        }

        return $out;
    }

    public static function clearCache(): void
    {
        self::$constraints = [];
    }

    /** @return list<ValidationError> */
    private function validateObject(object $object, array $options, string $path): array
    {
        $errors = [];
        $raw = class_exists(ClassTransformer::class) ? ClassTransformer::rawValues($object) : [];
        $groups = $options['groups'] ?? [];
        $always = (bool) ($options['always'] ?? false);
        $stop = (bool) ($options['stopAtFirstError'] ?? false);

        foreach (self::constraintsOf($object::class) as $property => $constraints) {
            $ref = new ReflectionProperty($object, $property);
            $defined = $ref->isInitialized($object);
            $value = $defined ? $ref->getValue($object) : null;
            if (array_key_exists($property, $raw)) {
                $value = $raw[$property];
                $defined = true;
            }
            $ctx = new ValidationContext($object, $property, $options, $this, $defined, $path);

            // conditional markers
            $optional = false;
            $skip = false;
            foreach ($constraints as $c) {
                if ($c instanceof IsOptional) {
                    $optional = true;
                } elseif ($c instanceof ValidateIf && ! $c->shouldValidate($object, $value)) {
                    $skip = true;
                }
            }
            if ($skip || ($optional && $value === null)) {
                continue;
            }
            $skipMissing = ($value === null && ! empty($options['skipMissingProperties']))
                || (! $defined && ! empty($options['skipUndefinedProperties']))
                || ($defined && $value === null && ! empty($options['skipNullProperties']));

            $failed = [];
            $children = [];
            foreach ($constraints as $c) {
                if ($c instanceof IsOptional || $c instanceof ValidateIf) {
                    continue;
                }
                if ($skipMissing && ! $c instanceof IsDefined) {
                    continue;
                }
                if (! self::inGroups($c, $groups, $always)) {
                    continue;
                }
                if ($c instanceof ValidateNested) {
                    $message = $c->validate($value, $ctx);
                    if ($message !== null) {
                        $failed[$c->name()] = $message;
                    } else {
                        $children = [...$children, ...$this->nested($value, $options, $path === '' ? $property : "$path.$property")];
                    }
                } else {
                    $message = $this->run($c, $value, $ctx);
                    if ($message !== null) {
                        $failed[$c->name()] = $message;
                    }
                }
                if ($stop && $failed !== []) {
                    break;
                }
            }
            if ($failed !== [] || $children !== []) {
                $errors[] = new ValidationError($property, $value, $failed, $children, $object);
            }
        }

        return $errors;
    }

    private function run(Constraint $c, mixed $value, ValidationContext $ctx): ?string
    {
        if (! $c->each) {
            return $c->validate($value, $ctx);
        }
        if (! is_array($value)) {
            $value = $value instanceof \Traversable ? iterator_to_array($value) : null;
        }
        if (! is_array($value)) {
            $message = $c->validate(null, $ctx) ?? "{$ctx->property} must be an array";

            return 'each value in ' . $message;
        }
        foreach ($value as $item) {
            $message = $c->validate($item, $ctx);
            if ($message !== null) {
                return 'each value in ' . $message;
            }
        }

        return null;
    }

    /** @return list<ValidationError> */
    private function nested(mixed $value, array $options, string $path): array
    {
        if (is_object($value)) {
            return $this->validateObject($value, $options, $path);
        }
        $children = [];
        foreach ((array) $value as $index => $item) {
            if (! is_object($item)) {
                continue;
            }
            $itemErrors = $this->validateObject($item, $options, "$path.$index");
            if ($itemErrors !== []) {
                $children[] = new ValidationError((string) $index, $item, [], $itemErrors, null);
            }
        }

        return $children;
    }

    /** @param list<string> $groups */
    private static function inGroups(Constraint $c, array $groups, bool $always): bool
    {
        if ($c->always) {
            return true;
        }
        if ($groups === []) {
            return $c->groups === [] || $always;
        }

        return array_intersect($c->groups, $groups) !== [];
    }

    /**
     * @param class-string $class
     * @return array<string, list<Constraint>>
     */
    private static function constraintsOf(string $class): array
    {
        if (isset(self::$constraints[$class])) {
            return self::$constraints[$class];
        }
        $map = [];
        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            $list = [];
            foreach ($prop->getAttributes(Constraint::class, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
                $list[] = $attr->newInstance();
            }
            if ($list !== []) {
                $map[$prop->getName()] = $list;
            }
        }

        return self::$constraints[$class] = $map;
    }
}
