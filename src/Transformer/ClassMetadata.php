<?php

declare(strict_types=1);

namespace Nesto\Transformer;

use Nesto\Transformer\Attributes\Expose;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Cached reflection view of a DTO / entity class used by ClassTransformer.
 * @internal
 */
final class ClassMetadata
{
    /** @var array<string, self> */
    private static array $cache = [];

    public readonly ReflectionClass $reflection;
    /** @var array<string, PropertyMetadata> keyed by property name */
    public readonly array $properties;
    /** @var list<\ReflectionParameter> */
    public readonly array $constructorParams;
    /** @var list<array{method: ReflectionMethod, expose: Expose, key: string}> computed values: public methods marked #[Expose] */
    public readonly array $exposedMethods;

    /** @param class-string $class */
    private function __construct(string $class)
    {
        $this->reflection = new ReflectionClass($class);
        $props = [];
        foreach ($this->reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $p) {
            if (! $p->isStatic()) {
                $props[$p->getName()] = new PropertyMetadata($p);
            }
        }
        $this->properties = $props;
        $this->constructorParams = $this->reflection->getConstructor()?->getParameters() ?? [];

        $methods = [];
        foreach ($this->reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            $attr = $m->getAttributes(Expose::class)[0] ?? null;
            if ($attr === null || $m->isStatic() || $m->getNumberOfRequiredParameters() > 0) {
                continue;
            }
            $expose = $attr->newInstance();
            $name = $m->getName();
            $key = $expose->name ?? self::computedKey($name);
            $methods[] = ['method' => $m, 'expose' => $expose, 'key' => $key];
        }
        $this->exposedMethods = $methods;
    }

    /**
     * Key of an #[Expose]d method: `getFullNameAttribute()` (Laravel accessor) and `getFullName()` both give
     * "fullName"; any other method name is used as it is.
     */
    public static function computedKey(string $method): string
    {
        if (preg_match('/^get([A-Z]\w*)Attribute$/', $method, $m) === 1) {
            return lcfirst($m[1]);
        }

        return preg_match('/^get[A-Z]/', $method) === 1 ? lcfirst(substr($method, 3)) : $method;
    }

    /** @param class-string $class */
    public static function of(string $class): self
    {
        return self::$cache[$class] ??= new self($class);
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Finds the property a plain key maps to (alias first, then property name, then naming strategy).
     */
    public function propertyForKey(string $key, ?string $namingStrategy): ?PropertyMetadata
    {
        foreach ($this->properties as $p) {
            if ($p->plainKey($namingStrategy) === $key) {
                return $p;
            }
        }
        if (isset($this->properties[$key])) {
            return $this->properties[$key];
        }
        // snake_case key → camelCase property (lenient)
        $camel = lcfirst(str_replace('_', '', ucwords($key, '_')));

        return $this->properties[$camel] ?? null;
    }
}
