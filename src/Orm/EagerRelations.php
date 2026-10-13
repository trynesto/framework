<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Nesto\Orm\Mapping\RelationType;

/**
 * `eager: true` relations (TypeORM): find*() / findOne*() load them on every query unless
 * `loadEagerRelations: false`, recursively (eager relations of eager relations, and of the relations
 * requested explicitly). The QueryBuilder never loads them.
 *
 * A cycle of eager relations (A::$b eager → B::$a eager, or a self-referencing eager relation) would load
 * forever: like TypeORM it is rejected with an OrmException, as soon as the entity is registered.
 */
final class EagerRelations
{
    /** @var array<class-string, array<string, array>> eager-only relation tree per entity class (metadata-derived) */
    private static array $cache = [];

    /**
     * The relation tree to load: $tree (['author' => ['profile' => []]]) plus every eager relation,
     * at every level.
     *
     * @param array<string, array> $tree
     * @return array<string, array>
     */
    public static function expand(EntityMetadata $meta, array $tree): array
    {
        foreach (self::of($meta) as $name => $eager) {
            $tree[$name] = isset($tree[$name]) ? self::merge($tree[$name], $eager) : $eager;
        }
        foreach ($tree as $name => $sub) {
            $relation = $meta->relation((string) $name) ?? throw new OrmException("Unknown relation \"$name\" on {$meta->class}.");
            if ($relation->type !== RelationType::MorphTo) {
                $tree[$name] = self::expand(MetadataRegistry::get($relation->target), $sub);
            }
        }

        return $tree;
    }

    /** Throws when the eager relations reachable from $meta form a cycle. */
    public static function assertNoCycles(EntityMetadata $meta): void
    {
        self::of($meta);
    }

    public static function clear(): void
    {
        self::$cache = [];
    }

    /** @return array<string, array> eager relations of $meta, nested */
    private static function of(EntityMetadata $meta): array
    {
        return self::$cache[$meta->class] ??= self::build($meta, [$meta->class => []]);
    }

    /**
     * @param array<class-string, list<string>> $chain classes entered through eager relations => the hops so far
     * @return array<string, array>
     */
    private static function build(EntityMetadata $meta, array $chain): array
    {
        $tree = [];
        foreach ($meta->relations as $name => $relation) {
            if (! $relation->eager) {
                continue;
            }
            $hops = [...end($chain), "{$meta->class}::\${$name}"];
            if ($relation->type === RelationType::MorphTo) {
                $tree[$name] = [];
                continue;
            }
            $target = $relation->target;
            if (isset($chain[$target])) {
                throw new OrmException(
                    'Circular eager relations are disallowed: ' . implode(' → ', $hops) . " (eager: true) lead back to $target."
                    . ' Remove eager: true from one of them and load that side with the `relations` find option.',
                );
            }
            $tree[$name] = self::build(MetadataRegistry::get($target), $chain + [$target => $hops]);
        }

        return $tree;
    }

    /** @param array<string, array> $a @param array<string, array> $b @return array<string, array> */
    public static function merge(array $a, array $b): array
    {
        foreach ($b as $key => $sub) {
            $a[$key] = isset($a[$key]) ? self::merge($a[$key], $sub) : $sub;
        }

        return $a;
    }
}
