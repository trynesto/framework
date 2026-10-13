<?php

declare(strict_types=1);

namespace Nesto\Orm\Events;

use Nesto\Orm\Attributes\AfterInsert;
use Nesto\Orm\Attributes\AfterLoad;
use Nesto\Orm\Attributes\AfterRecover;
use Nesto\Orm\Attributes\AfterRemove;
use Nesto\Orm\Attributes\AfterSoftRemove;
use Nesto\Orm\Attributes\AfterUpdate;
use Nesto\Orm\Attributes\BeforeInsert;
use Nesto\Orm\Attributes\BeforeRecover;
use Nesto\Orm\Attributes\BeforeRemove;
use Nesto\Orm\Attributes\BeforeSoftRemove;
use Nesto\Orm\Attributes\BeforeUpdate;
use ReflectionClass;
use ReflectionMethod;

/**
 * Entity listener methods (#[BeforeInsert], #[AfterLoad], …) per entity class and event, parent classes first.
 * Class-level metadata (the same for every request), cached like MetadataRegistry and cleared with it.
 */
final class EntityListeners
{
    /** event (subscriber method name) => listener attribute */
    public const ATTRIBUTES = [
        'afterLoad' => AfterLoad::class,
        'beforeInsert' => BeforeInsert::class,
        'afterInsert' => AfterInsert::class,
        'beforeUpdate' => BeforeUpdate::class,
        'afterUpdate' => AfterUpdate::class,
        'beforeRemove' => BeforeRemove::class,
        'afterRemove' => AfterRemove::class,
        'beforeSoftRemove' => BeforeSoftRemove::class,
        'afterSoftRemove' => AfterSoftRemove::class,
        'beforeRecover' => BeforeRecover::class,
        'afterRecover' => AfterRecover::class,
    ];

    /** @var array<class-string, array<string, list<ReflectionMethod>>> */
    private static array $cache = [];

    /** @return list<ReflectionMethod> listener methods of $class for $event (e.g. "beforeInsert") */
    public static function for(string $class, string $event): array
    {
        return (self::$cache[$class] ??= self::build($class))[$event] ?? [];
    }

    public static function has(string $class, string $event): bool
    {
        return self::for($class, $event) !== [];
    }

    /** Invoke the $event listeners on $entity. */
    public static function call(object $entity, string $event): void
    {
        foreach (self::for($entity::class, $event) as $method) {
            $method->invoke($entity);
        }
    }

    public static function clear(): void
    {
        self::$cache = [];
    }

    /** @return array<string, list<ReflectionMethod>> */
    private static function build(string $class): array
    {
        $chain = [];
        for ($ref = new ReflectionClass($class); $ref !== false; $ref = $ref->getParentClass()) {
            array_unshift($chain, $ref);
        }
        $listeners = [];
        $seen = [];
        foreach ($chain as $ref) {
            foreach ($ref->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $ref->getName() || $method->isStatic()) {
                    continue;
                }
                $key = $method->isPrivate() ? $ref->getName() . '::' . $method->getName() : $method->getName();
                foreach (self::ATTRIBUTES as $event => $attribute) {
                    if ($method->getAttributes($attribute) === [] || isset($seen[$event][$key])) {
                        continue;
                    }
                    if ($method->getNumberOfRequiredParameters() > 0) {
                        throw new \Nesto\Orm\OrmException("Entity listener $class::{$method->getName()}() must not require parameters.");
                    }
                    $seen[$event][$key] = true;
                    // an overridden (non-private) method dispatches to the override, like a normal call would
                    $listeners[$event][] = $method->isPrivate() ? $method : new ReflectionMethod($class, $method->getName());
                }
            }
        }

        return $listeners;
    }
}
