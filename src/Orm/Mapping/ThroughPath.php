<?php

declare(strict_types=1);

namespace Nesto\Orm\Mapping;

use Nesto\Orm\EntityMetadata;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\OrmException;

/**
 * The resolved table chain of a through relation (HasOneThrough / HasManyThrough / BelongsToThrough):
 * the key read on the parent row, then each table to join, ending with the target.
 *
 *   Country::$posts  (HasManyThrough Post via User):
 *     parentKey "id"  →  users.country_id (out users.id)  →  posts.user_id
 *   Post::$country   (BelongsToThrough Country via User):
 *     parentKey "user_id"  →  users.id (out users.country_id)  →  countries.id
 *
 * Resolved lazily (entities may reference each other) and cached per relation.
 */
final class ThroughPath
{
    /** @var array<string, self> */
    private static array $cache = [];

    /**
     * @param string $parentKey column of the parent table whose value enters the chain
     * @param list<array{meta: EntityMetadata, in: string, out: ?string}> $hops intermediates first, target last;
     *        `in` is matched against the previous value (parentKey / previous hop's `out`)
     */
    public function __construct(public readonly string $parentKey, public readonly array $hops) {}

    public static function for(EntityMetadata $meta, RelationMetadata $relation): self
    {
        $key = $meta->class . '::' . $relation->property;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $path = $relation->type === RelationType::BelongsToThrough ? self::belongsTo($meta, $relation) : self::hasThrough($meta, $relation);
        foreach ([[$meta, $path->parentKey], ...array_map(fn (array $h) => [$h['meta'], $h['in']], $path->hops), ...array_map(fn (array $h) => [$h['meta'], $h['out']], $path->hops)] as [$m, $column]) {
            if ($column !== null && ! isset($m->columns[$column])) {
                throw new OrmException("Through relation {$meta->class}::\${$relation->property}: column \"$column\" does not exist on {$m->class} ({$m->table}); set the keys on the attribute.");
            }
        }

        return self::$cache[$key] = $path;
    }

    public function target(): EntityMetadata
    {
        return $this->hops[count($this->hops) - 1]['meta'];
    }

    private static function hasThrough(EntityMetadata $meta, RelationMetadata $relation): self
    {
        $through = MetadataRegistry::get($relation->through[0]);
        $target = MetadataRegistry::get($relation->target);
        $keys = $relation->throughKeys;

        $first = self::link($through, $meta->class);
        $firstKey = isset($keys['firstKey']) ? $through->columnName($keys['firstKey']) : ($first?->joinColumn ?? self::fkName($meta->class));
        $localKey = $meta->columnName($keys['localKey'] ?? $first?->referencedColumn ?? (string) $meta->primary->property);

        $second = self::link($target, $through->class);
        $secondKey = isset($keys['secondKey']) ? $target->columnName($keys['secondKey']) : ($second?->joinColumn ?? self::fkName($through->class));
        $secondLocalKey = $through->columnName($keys['secondLocalKey'] ?? $second?->referencedColumn ?? (string) $through->primary->property);

        return new self($localKey, [
            ['meta' => $through, 'in' => $firstKey, 'out' => $secondLocalKey],
            ['meta' => $target, 'in' => $secondKey, 'out' => null],
        ]);
    }

    private static function belongsTo(EntityMetadata $meta, RelationMetadata $relation): self
    {
        $foreignKeys = $relation->throughKeys['foreignKeys'] ?? [];
        $localKeys = $relation->throughKeys['localKeys'] ?? [];
        $target = MetadataRegistry::get($relation->target);
        $given = array_map(MetadataRegistry::get(...), $relation->through);

        // `through` is listed from the target side (staudenmeir/belongs-to-through) or from this side:
        // keep the order whose foreign keys actually link this entity to the target.
        $best = null;
        $bestScore = -1;
        foreach ([array_reverse($given), $given] as $candidate) {
            $chain = [$meta, ...$candidate, $target];
            $score = 0;
            for ($i = 0; $i < count($chain) - 1; $i++) {
                if (isset($foreignKeys[$chain[$i + 1]->class]) || self::link($chain[$i], $chain[$i + 1]->class) !== null) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [$chain, $score];
            }
        }

        $fks = [];
        $refs = [];
        for ($i = 0; $i < count($best) - 1; $i++) {
            [$from, $to] = [$best[$i], $best[$i + 1]];
            $link = self::link($from, $to->class);
            $fks[$i] = isset($foreignKeys[$to->class]) ? $from->columnName($foreignKeys[$to->class]) : ($link?->joinColumn ?? self::fkName($to->class));
            $refs[$i] = $to->columnName($localKeys[$to->class] ?? $link?->referencedColumn ?? (string) $to->primary->property);
        }
        $hops = [];
        for ($j = 1; $j < count($best); $j++) {
            $hops[] = ['meta' => $best[$j], 'in' => $refs[$j - 1], 'out' => $fks[$j] ?? null];
        }

        return new self($fks[0], $hops);
    }

    /** The owning to-one relation (ManyToOne / OneToOne with a join column) of $from pointing at $toClass. */
    private static function link(EntityMetadata $from, string $toClass): ?RelationMetadata
    {
        foreach ($from->relations as $relation) {
            if ($relation->joinColumn !== null && $relation->target === $toClass) {
                return $relation;
            }
        }

        return null;
    }

    private static function fkName(string $class): string
    {
        return Naming::snake(Naming::shortName($class)) . '_id';
    }

    /** Called by MetadataRegistry::clear(). */
    public static function clear(): void
    {
        self::$cache = [];
    }
}
