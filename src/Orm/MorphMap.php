<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Nesto\Orm\Attributes\MorphAlias;
use ReflectionClass;

/**
 * Maps entity classes to the short names stored in polymorphic type columns (Laravel's morph map).
 * Global, like Laravel's Relation::morphMap(). Without an alias the class name itself is stored.
 *
 *   MorphMap::map(['post' => Post::class, 'video' => Video::class]);   // what the `morphMap` option does
 *   #[Entity, MorphAlias('post')] final class Post {}                    // same, declared on the entity
 *
 *   MorphMap::aliasFor(Post::class);   // "post"
 *   MorphMap::classFor('post');        // Post::class
 */
final class MorphMap
{
    /** @var array<string, class-string> alias => class (from map()) */
    private static array $map = [];
    /** @var array<string, class-string> alias => class (from #[MorphAlias]) */
    private static array $attributes = [];
    /** @var array<class-string, ?string> class => #[MorphAlias] alias (reflection cache) */
    private static array $scanned = [];

    /** @param array<string, class-string> $map alias => entity class */
    public static function map(array $map, bool $merge = true): void
    {
        if (! $merge) {
            self::$map = [];
        }
        foreach ($map as $alias => $class) {
            $alias = (string) $alias;
            if (isset(self::$map[$alias]) && self::$map[$alias] !== $class) {
                throw new OrmException("Morph alias \"$alias\" is already mapped to " . self::$map[$alias] . ", cannot map it to $class.");
            }
            self::$map[$alias] = ltrim($class, '\\');
        }
    }

    /** @return array<string, class-string> every known alias => class */
    public static function all(): array
    {
        return self::$map + self::$attributes;
    }

    /** Register the #[MorphAlias] of an entity class (MetadataRegistry does this while building metadata). */
    public static function registerClass(string $class): ?string
    {
        if (array_key_exists($class, self::$scanned)) {
            return self::$scanned[$class];
        }
        $alias = null;
        if (class_exists($class)) {
            $alias = ((new ReflectionClass($class))->getAttributes(MorphAlias::class)[0] ?? null)?->newInstance()->alias;
        }
        self::$scanned[$class] = $alias;
        if ($alias !== null) {
            $existing = self::$attributes[$alias] ?? self::$map[$alias] ?? null;
            if ($existing !== null && $existing !== $class) {
                throw new OrmException("Morph alias \"$alias\" of $class is already used by $existing.");
            }
            self::$attributes[$alias] = $class;
        }

        return $alias;
    }

    /** Value stored in a type column for an entity class: its alias, or the class name. */
    public static function aliasFor(string $class): string
    {
        $found = array_search($class, self::$map, true);
        if ($found !== false) {
            return (string) $found;
        }

        return self::registerClass($class) ?? $class;
    }

    /** Entity class for a stored type value (alias or class name). */
    public static function classFor(string $type): string
    {
        if (isset(self::$map[$type])) {
            return self::$map[$type];
        }
        if (isset(self::$attributes[$type])) {
            return self::$attributes[$type];
        }
        if (class_exists($type) && MetadataRegistry::has($type)) {
            return ltrim($type, '\\');
        }

        throw new OrmException("Unknown morph type \"$type\": map it with the `morphMap` option (MorphMap::map()), #[MorphAlias] on the entity, or MorphTo(types: [...]).");
    }

    /** Forget every alias (tests). */
    public static function clear(): void
    {
        self::$map = [];
        self::$attributes = [];
        self::$scanned = [];
    }
}
