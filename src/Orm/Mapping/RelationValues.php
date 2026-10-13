<?php

declare(strict_types=1);

namespace Nesto\Orm\Mapping;

use Nesto\Orm\EntityMetadata;
use Nesto\Orm\Hydrator;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\MorphMap;
use Nesto\Orm\OrmException;

/**
 * Relation values in the column-level writes — Repository::update() / insert(), QueryBuilder set() / values() — as
 * TypeORM takes them: a to-one relation is written through its join column, given an entity, a plain array with
 * the referenced key (['id' => 5]), the key itself (5) or null; a MorphTo writes its type and id columns.
 *
 *   $posts->update($id, ['author' => $ada]);   ['author' => ['id' => 5]];   ['author' => 5];   ['author' => null]
 */
final class RelationValues
{
    /**
     * Column name => database value for the value of relation $key; null when $key is no relation property.
     *
     * @return array<string, mixed>|null
     */
    public static function columns(EntityMetadata $meta, string $key, mixed $value, string $operation): ?array
    {
        $relation = $meta->relation($key);
        if ($relation === null || $meta->column($key) !== null) {
            return null;
        }
        if ($relation->type === RelationType::MorphTo) {
            return self::morphColumns($meta, $relation, $value, $operation);
        }
        if ($relation->joinColumn === null) {
            throw new OrmException("$operation: {$meta->class}::\$$key is the " . ($relation->isToMany() ? 'to-many' : 'inverse')
                . ' side of a relation and has no column to write: set it from the owning side (or save() the entity).');
        }
        $column = $meta->columns[$relation->joinColumn];

        return [$column->name => TypeConverter::toDatabase($column, self::referencedKey($meta, $relation, $value, $operation))];
    }

    /** The referenced key (as a PHP value) a to-one relation value points at. */
    private static function referencedKey(EntityMetadata $meta, RelationMetadata $relation, mixed $value, string $operation): mixed
    {
        if ($value === null || is_scalar($value) || (is_object($value) && ! MetadataRegistry::has($value::class))) {
            return $value;
        }
        $targetMeta = MetadataRegistry::get(is_object($value) ? $value::class : $relation->target);
        $referenced = $targetMeta->column($relation->referencedColumn) ?? $targetMeta->primary;
        if (is_object($value)) {
            if (! PropertyAccess::isInitialized($value, $referenced)) {
                throw new OrmException("$operation: the " . $value::class . " given for {$meta->class}::\${$relation->property} has no \"{$referenced->property}\" (save it first).");
            }

            return PropertyAccess::get($value, $referenced);
        }
        if (is_array($value) && ! array_is_list($value)) {
            foreach ([(string) $referenced->property, $referenced->name] as $name) {
                if (array_key_exists($name, $value)) {
                    return $value[$name];
                }
            }
        }
        throw new OrmException("$operation: {$meta->class}::\${$relation->property} takes an entity, its key or ['{$referenced->property}' => …]; got " . get_debug_type($value) . ' without "' . $referenced->property . '".');
    }

    /** @return array<string, mixed> */
    private static function morphColumns(EntityMetadata $meta, RelationMetadata $relation, mixed $value, string $operation): array
    {
        $type = (string) $relation->morphTypeColumn;
        $id = (string) $relation->morphIdColumn;
        if ($value === null) {
            return [$type => null, $id => null];
        }
        if (! is_object($value)) {
            throw new OrmException("$operation: MorphTo {$meta->class}::\${$relation->property} takes an entity or null; set the \"$type\" and \"$id\" columns to point at a row by type and id.");
        }
        $targetMeta = MetadataRegistry::get($value::class);
        $key = $targetMeta->primaryValue($value) ?? throw new OrmException("$operation: the " . $value::class . " given for {$meta->class}::\${$relation->property} has no primary key (save it first).");

        return [$type => MorphMap::aliasFor($value::class), $id => Hydrator::morphKey($meta->columns[$id] ?? null, TypeConverter::toDatabase($targetMeta->primary, $key))];
    }
}
