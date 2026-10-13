<?php

declare(strict_types=1);

namespace Nesto\Core;

use Nesto\Common\Attributes\SetMetadata;
use ReflectionClass;
use ReflectionMethod;

/**
 * Nest's Reflector: read attributes / #[SetMetadata] off classes and handlers.
 *
 *   $reflector->get(Roles::class, $ctx->getHandler())              → ?Roles
 *   $reflector->getAllAndOverride(Roles::class, [$handler, $class]) → first found
 *   $reflector->getAllAndMerge(Roles::class, [$handler, $class])    → list of all found
 *   $reflector->metadata('roles', $handler)                        → value set with #[SetMetadata('roles', …)]
 */
final class Reflector
{
    /** @var array<string, list<object>> */
    private array $cache = [];

    /**
     * @template T of object
     * @param class-string<T>                               $attribute
     * @param ReflectionMethod|ReflectionClass|string|array $target class name, [class, method], or reflection
     * @return T|null
     */
    public function get(string $attribute, ReflectionMethod|ReflectionClass|string|array $target): ?object
    {
        return $this->all($attribute, $target)[0] ?? null;
    }

    /** @return list<object> */
    public function all(string $attribute, ReflectionMethod|ReflectionClass|string|array $target): array
    {
        $ref = $this->reflect($target);
        $key = $attribute . '@' . ($ref instanceof ReflectionMethod ? $ref->class . '::' . $ref->name : $ref->name);
        if (! isset($this->cache[$key])) {
            $this->cache[$key] = array_map(fn ($a) => $a->newInstance(), $ref->getAttributes($attribute, \ReflectionAttribute::IS_INSTANCEOF));
        }

        return $this->cache[$key];
    }

    /** First target that has the attribute wins (handler usually before class). */
    public function getAllAndOverride(string $attribute, array $targets): ?object
    {
        foreach ($targets as $t) {
            $found = $this->get($attribute, $t);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @return list<object> */
    public function getAllAndMerge(string $attribute, array $targets): array
    {
        $out = [];
        foreach ($targets as $t) {
            $out = [...$out, ...$this->all($attribute, $t)];
        }

        return $out;
    }

    /** Value registered with #[SetMetadata($key, $value)]; handler overrides class. */
    public function metadata(string $key, ReflectionMethod|ReflectionClass|string|array ...$targets): mixed
    {
        foreach ($targets as $t) {
            foreach ($this->all(SetMetadata::class, $t) as $m) {
                if ($m->key === $key) {
                    return $m->value;
                }
            }
        }

        return null;
    }

    public function reflect(object|string|array $target): ReflectionMethod|ReflectionClass
    {
        if ($target instanceof ReflectionMethod || $target instanceof ReflectionClass) {
            return $target;
        }
        if (is_array($target)) {
            return new ReflectionMethod(is_object($target[0]) ? $target[0]::class : $target[0], $target[1]);
        }

        return new ReflectionClass($target);
    }
}
