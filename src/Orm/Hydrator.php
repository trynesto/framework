<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Nesto\Orm\Mapping\ColumnMetadata;
use Nesto\Orm\Mapping\PropertyAccess;
use Nesto\Orm\Mapping\RelationMetadata;
use Nesto\Orm\Mapping\RelationType;
use Nesto\Orm\Mapping\TypeConverter;
use Nesto\Orm\Types\TypeRegistry;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Turns database rows into entities (with type conversion) and entities back into rows.
 * Row snapshots are recorded in EntityState so later saves only write changed columns.
 */
final class Hydrator
{
    private ?LazyRelations $lazy = null;
    /** @var array<string, bool> "Class::property" => whether an #[Embedded] property accepts null (class metadata) */
    private static array $nullableEmbedded = [];
    /** @var (\Closure(object, EntityMetadata): void)|null told about every hydrated entity (AfterLoad) */
    private ?\Closure $onHydrate = null;

    public function __construct(private readonly EntityState $state) {}

    /** Installs lazy relation placeholders on every hydrated entity (set by the DataSource). */
    public function setLazyRelations(?LazyRelations $lazy): void
    {
        $this->lazy = $lazy;
    }

    /** @param (\Closure(object, EntityMetadata): void)|null $listener called after each hydrate() (set by the DataSource) */
    public function onHydrate(?\Closure $listener): void
    {
        $this->onHydrate = $listener;
    }

    /**
     * Build an entity from a row. With a prefix ("u__"), only keys starting with it are used;
     * returns null when the primary key is absent/NULL (an empty LEFT JOIN side). `$into` fills an existing
     * instance instead of a new one (a lazy ghost being initialized).
     *
     * @param array<string, mixed> $row
     */
    public function hydrate(EntityMetadata $meta, array $row, string $prefix = '', ?object $into = null): ?object
    {
        $pkKey = $prefix . $meta->primary->name;
        if (! array_key_exists($pkKey, $row) || $row[$pkKey] === null) {
            return null;
        }
        if ($into === null && count($meta->discriminatorMap) > 1) {
            $meta = self::inheritedMetadata($meta, $row, $prefix);
        }
        $entity = $into ?? (new ReflectionClass($meta->class))->newInstanceWithoutConstructor();
        $snapshot = [];
        $nullEmbedded = $this->nullEmbedded($meta, $row, $prefix);
        foreach ($meta->columns as $column) {
            $key = $prefix . $column->name;
            if ($nullEmbedded !== [] && $column->embedded !== [] && self::under((string) $column->property, $nullEmbedded)) {
                if (array_key_exists($key, $row)) {
                    $snapshot[$column->name] = $row[$key];
                }
                continue;
            }
            if (! array_key_exists($key, $row)) {
                // partial selection: an unselected column is "not loaded" (TypeORM's undefined), not its class
                // default, so save() never writes the default back over the stored value
                if ($column->property !== null && PropertyAccess::isInitialized($entity, $column)) {
                    PropertyAccess::unset($entity, $column);
                }
                continue;
            }
            $snapshot[$column->name] = $row[$key];
            if ($column->property !== null) {
                $this->assign($entity, $column, $row[$key]);
            }
        }
        // An owning to-one property declared with a default (`public ?User $author = null;`) is null on a
        // fresh instance even though the row has a foreign key: mark it "not loaded" (uninitialized, like a
        // property without default) so foreign keys come from the snapshot and save() does not clear them.
        // Same for a MorphTo (`public ?object $commentable = null;`) whose row has a type + id.
        foreach ($meta->relations as $relation) {
            $keyColumn = $relation->joinColumn ?? ($relation->type === RelationType::MorphTo ? $relation->morphIdColumn : null);
            if ($keyColumn !== null && ($snapshot[$keyColumn] ?? null) !== null
                && $meta->isInitialized($entity, $relation->property) && $entity->{$relation->property} === null) {
                unset($entity->{$relation->property});
            }
        }
        foreach ($nullEmbedded as $path) {
            $holder = $entity;
            $segments = explode('.', $path);
            $last = array_pop($segments);
            foreach ($segments as $segment) {
                $holder = $holder->{$segment};
            }
            $holder->{$last} = null;
        }
        $this->state->setSnapshot($entity, $snapshot);
        // Inverse / to-many relation properties still at their class default (`= null`, `= []`) were not loaded:
        // save() must not read them as "detach everything" (TypeORM leaves unloaded relations undefined).
        $defaults = [];
        foreach ($meta->relations as $relation) {
            if (! $relation->holdsForeignKey() && $relation->type !== RelationType::MorphTo && $meta->isInitialized($entity, $relation->property)
                && ($entity->{$relation->property} === null || $entity->{$relation->property} === [])) {
                $defaults[] = $relation->property;
            }
        }
        $this->state->markDefaultRelations($entity, $defaults);
        $this->lazy?->install($meta, $entity);
        if ($this->onHydrate !== null) {
            ($this->onHydrate)($entity, $meta);
        }

        return $entity;
    }

    /**
     * Single table inheritance: the metadata of the class the row's discriminator names (a parent's query returns
     * its children as their own classes). The row's value must be known when the parent is abstract.
     */
    private static function inheritedMetadata(EntityMetadata $meta, array $row, string $prefix): EntityMetadata
    {
        $key = $prefix . $meta->discriminatorColumn;
        if (! array_key_exists($key, $row)) {
            return $meta; // not selected: hydrate as the queried class
        }
        $class = $meta->discriminatorMap[(string) $row[$key]] ?? null;
        if ($class === null) {
            if ((new ReflectionClass($meta->class))->isAbstract()) {
                throw new OrmException("{$meta->table}.{$meta->discriminatorColumn} holds \"{$row[$key]}\", which is not the discriminator value of {$meta->class} or of its child entities (" . implode(', ', array_keys($meta->discriminatorMap)) . ').');
            }

            return $meta;
        }

        return $class === $meta->class ? $meta : MetadataRegistry::get($class);
    }

    /** Hydrate a plain array (request body, fixture) into an UNSAVED entity, converting scalar types. */
    public function fromPlain(EntityMetadata $meta, array $plain): object
    {
        $entity = (new ReflectionClass($meta->class))->newInstanceWithoutConstructor();
        $this->merge($meta, $entity, $plain);

        return $entity;
    }

    /** Copy plain values onto an existing entity (keys are property or column names). */
    public function merge(EntityMetadata $meta, object $entity, array $plain): void
    {
        $given = [];
        foreach ($plain as $key => $value) {
            $column = $meta->column((string) $key);
            if ($column === null && is_array($value) && $meta->embeddedColumns((string) $key) !== []) {
                continue; // an #[Embedded] array: its own keys are marked as they are merged
            }
            $given[] = $column?->property ?? (string) ($column?->relation ?? $key);
        }
        $this->state->markGiven($entity, $given);
        foreach ($plain as $key => $value) {
            $column = $meta->column((string) $key);
            if ($column !== null && $column->property !== null) {
                $this->assign($entity, $column, $value, fromDatabase: false);
                continue;
            }
            if ($column === null && ($embedded = $meta->embeddedColumns((string) $key)) !== []) {
                $this->mergeEmbedded($meta, $entity, (string) $key, $embedded, $value);
                continue;
            }
            if ($column !== null && $column->relation !== null && ! is_array($value) && ! is_object($value)) {
                // implicit foreign-key / morph column given by name: ['user_id' => 5], ['commentable_type' => 'post'];
                // a `= null` default of the relation property then means "not set" rather than "detach"
                $this->state->setPendingForeignKey($entity, $column->name, $value);
                $relationProperty = (string) $column->relation;
                if ($meta->isInitialized($entity, $relationProperty) && $entity->{$relationProperty} === null && ! array_key_exists($relationProperty, $plain)) {
                    unset($entity->{$relationProperty});
                }
                continue;
            }
            $relation = $meta->relation((string) $key);
            if ($relation !== null) {
                $this->assignRelation($entity, $relation, $value);
                continue;
            }
            if (property_exists($entity, (string) $key)) {
                $entity->{$key} = $value;
            }
        }
    }

    /**
     * Column name => database value for every initialized property, including foreign keys
     * derived from relation objects, pending cascades or the original snapshot.
     *
     * @return array<string, mixed>
     */
    public function toRow(EntityMetadata $meta, object $entity): array
    {
        $row = [];
        foreach ($meta->columns as $column) {
            if ($column->morph !== null) {
                $relation = $meta->relation((string) $column->relation);
                $assigned = $meta->isInitialized($entity, $relation->property);
                $hasProperty = $column->property !== null && $meta->isInitialized($entity, $column->property);
                if ($assigned && ($entity->{$relation->property} !== null || ! $hasProperty)) {
                    // an assigned entity wins; null clears the columns unless they are declared as properties
                    [$type, $id] = $this->morphValues($meta, $entity, $relation);
                    $row[$column->name] = $column->morph === 'type' ? $type : self::morphKey($column, $id);
                } elseif ($hasProperty) {
                    $row[$column->name] = TypeConverter::toDatabase($column, $entity->{$column->property});
                } elseif (($pending = $this->state->pendingForeignKey($entity, $column->name)) !== null) {
                    $row[$column->name] = $column->morph === 'type' ? (string) $pending : self::morphKey($column, $pending);
                }
                continue;
            }
            if ($column->relation !== null) {
                $relation = $meta->relation($column->relation);
                $fk = $this->foreignKeyValue($meta, $entity, $relation);
                if ($fk !== null || $this->relationExplicitlyNull($entity, $relation)) {
                    $row[$column->name] = $fk;
                } elseif ($column->property !== null && $meta->isInitialized($entity, $column->property)) {
                    $row[$column->name] = TypeConverter::toDatabase($column, $entity->{$column->property});
                }
                continue;
            }
            if ($column->property === null || ! PropertyAccess::isInitialized($entity, $column)) {
                continue;
            }
            $row[$column->name] = TypeConverter::toDatabase($column, PropertyAccess::get($entity, $column));
        }
        if ($meta->discriminatorColumn !== null && ! isset($row[$meta->discriminatorColumn])) {
            $row[$meta->discriminatorColumn] = $meta->discriminatorValue; // single table inheritance: the row's type
        }

        return $row;
    }

    /** The FK value an owning to-one relation currently points to (object → its id, column, pending, snapshot). */
    public function foreignKeyValue(EntityMetadata $meta, object $entity, RelationMetadata $relation): mixed
    {
        if ($relation->joinColumn === null) {
            return null;
        }
        if ($meta->isInitialized($entity, $relation->property)) {
            $target = $entity->{$relation->property};
            if (is_object($target)) {
                $targetMeta = MetadataRegistry::get($relation->target);
                $column = $targetMeta->column($relation->referencedColumn) ?? $targetMeta->primary;
                $prop = $column->property;

                return $prop !== null && $targetMeta->isInitialized($target, $prop)
                    ? TypeConverter::toDatabase($column, $target->{$prop})
                    : null;
            }
            if ($target === null) {
                return null;
            }

            return $target;
        }
        $column = $meta->columns[$relation->joinColumn];
        if ($column->property !== null && $meta->isInitialized($entity, $column->property)) {
            return TypeConverter::toDatabase($column, $entity->{$column->property});
        }
        $pending = $this->state->pendingForeignKey($entity, $relation->joinColumn);
        if ($pending !== null) {
            return $pending;
        }

        return $this->state->snapshot($entity)[$relation->joinColumn] ?? null;
    }

    /**
     * [type, id] a MorphTo currently points to: from the assigned entity (alias + primary key), else the
     * type / id columns (property, pending value, snapshot). [null, null] when it points nowhere.
     *
     * @return array{0: ?string, 1: mixed}
     */
    public function morphValues(EntityMetadata $meta, object $entity, RelationMetadata $relation): array
    {
        if ($meta->isInitialized($entity, $relation->property)) {
            $target = $entity->{$relation->property};
            if (! is_object($target)) {
                return [null, null];
            }
            $targetMeta = MetadataRegistry::get($target::class);

            return [MorphMap::aliasFor($target::class), TypeConverter::toDatabase($targetMeta->primary, $targetMeta->primaryValue($target))];
        }
        $type = $this->columnValue($meta, $entity, (string) $relation->morphTypeColumn);

        return [$type === null ? null : (string) $type, $this->columnValue($meta, $entity, (string) $relation->morphIdColumn)];
    }

    /**
     * Database value of a column of a hydrated/new entity: its property, the FK of its relation,
     * a pending value or the loaded row.
     */
    public function columnValue(EntityMetadata $meta, object $entity, string $columnName): mixed
    {
        $column = $meta->columns[$columnName] ?? null;
        if ($column !== null && $column->relation !== null && $column->morph === null) {
            return $this->foreignKeyValue($meta, $entity, $meta->relation($column->relation));
        }
        if ($column?->property !== null && PropertyAccess::isInitialized($entity, $column)) {
            return TypeConverter::toDatabase($column, PropertyAccess::get($entity, $column));
        }
        $pending = $this->state->pendingForeignKey($entity, $columnName);
        if ($pending !== null) {
            return $pending;
        }

        return $this->state->snapshot($entity)[$columnName] ?? null;
    }

    /** Morph id as stored in its column: integer for integer columns, string otherwise (uuid / mixed keys). */
    public static function morphKey(?ColumnMetadata $column, mixed $id): mixed
    {
        if ($id === null) {
            return null;
        }
        if ($column !== null && in_array($column->type, ['int', 'integer', 'bigint', 'smallint'], true)) {
            return is_numeric($id) ? (int) $id : $id;
        }

        return is_scalar($id) ? (string) $id : $id;
    }

    private function relationExplicitlyNull(object $entity, RelationMetadata $relation): bool
    {
        $ref = new ReflectionProperty($entity, $relation->property);

        return $ref->isInitialized($entity) && $entity->{$relation->property} === null;
    }

    /**
     * Set a column's property from a database value, or from plain input ($fromDatabase false: scalars are
     * still type-converted, but values of columns with transformers or a custom type are PHP values already).
     */
    private function assign(object $entity, ColumnMetadata $column, mixed $value, bool $fromDatabase = true): void
    {
        $converted = ! $fromDatabase && ($column->transformers !== [] || TypeRegistry::get($column->type) !== null)
            ? $value
            : TypeConverter::toPhp($column, $value);
        if ($converted === null && ! PropertyAccess::allowsNull($entity, $column)) {
            return; // leave uninitialized rather than violating the property type
        }
        PropertyAccess::set($entity, $column, $converted);
    }

    /**
     * Nullable #[Embedded] properties (`?Address $billing`) whose columns are all NULL in the row: they hydrate
     * as null instead of an empty object (outermost paths only).
     *
     * @return list<string> dotted property paths
     */
    private function nullEmbedded(EntityMetadata $meta, array $row, string $prefix): array
    {
        $paths = [];
        foreach ($meta->columns as $column) {
            $holderClass = $meta->class;
            $path = '';
            foreach ($column->embedded as [$property, $class]) {
                $path .= ($path === '' ? '' : '.') . $property;
                $paths[$path] ??= ['nullable' => self::$nullableEmbedded[$holderClass . '::' . $property]
                    ??= (new ReflectionProperty($holderClass, $property))->getType()?->allowsNull() ?? true, 'values' => false, 'selected' => false];
                if (array_key_exists($prefix . $column->name, $row)) {
                    $paths[$path]['selected'] = true;
                    $paths[$path]['values'] = $paths[$path]['values'] || $row[$prefix . $column->name] !== null;
                }
                $holderClass = $class;
            }
        }
        $null = [];
        foreach ($paths as $path => $info) {
            if ($info['nullable'] && $info['selected'] && ! $info['values'] && ! self::under($path, $null)) {
                $null[] = $path;
            }
        }

        return $null;
    }

    /** @param list<string> $paths */
    private static function under(string $property, array $paths): bool
    {
        foreach ($paths as $path) {
            if ($property === $path || str_starts_with($property, $path . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Plain input for an #[Embedded] property: an array of its properties (nested embeddeds nest), or an
     * object assigned as is.
     *
     * @param list<ColumnMetadata> $columns
     */
    private function mergeEmbedded(EntityMetadata $meta, object $entity, string $path, array $columns, mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->merge($meta, $entity, [$path . '.' . $key => $item]);
            }

            return;
        }
        if ($value !== null && ! is_object($value)) {
            throw new OrmException("Embedded \$$path takes an array of its properties or an object, got " . get_debug_type($value) . '.');
        }
        $depth = substr_count($path, '.');
        $holder = $entity;
        foreach (array_slice($columns[0]->embedded, 0, $depth) as [$property, $class]) {
            if (! (new ReflectionProperty($holder, $property))->isInitialized($holder) || $holder->{$property} === null) {
                $holder->{$property} = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            }
            $holder = $holder->{$property};
        }
        $property = $columns[0]->embedded[$depth][0];
        if ($value !== null || self::allowsNull($holder, $property)) {
            $holder->{$property} = $value;
        }
    }

    private function assignRelation(object $entity, RelationMetadata $relation, mixed $value): void
    {
        if ($relation->isReadOnly()) {
            return; // through relations are computed, never written
        }
        $this->state->touchRelation($entity, $relation->property);
        if ($relation->type === RelationType::MorphTo) {
            if ($value !== null && ! is_object($value)) {
                throw new OrmException("MorphTo \${$relation->property} accepts an entity or null; set the \"{$relation->morphTypeColumn}\" and \"{$relation->morphIdColumn}\" columns to point at a row by type and id.");
            }
            if ($value !== null || self::allowsNull($entity, $relation->property)) {
                $entity->{$relation->property} = $value;
            }

            return;
        }
        $targetMeta = MetadataRegistry::get($relation->target);
        if ($relation->isToMany()) {
            $items = [];
            foreach (is_array($value) ? $value : [] as $item) {
                $items[] = match (true) {
                    is_array($item) => $this->fromPlain($targetMeta, $item),
                    is_object($item) => $item,
                    default => $this->fromPlain($targetMeta, [(string) $targetMeta->primary->property => $item]),   // bare id
                };
            }
            $entity->{$relation->property} = $items;

            return;
        }
        if (is_array($value)) {
            $value = $this->fromPlain($targetMeta, $value);
        }
        if ($value === null && ! self::allowsNull($entity, $relation->property)) {
            return;
        }
        if (is_object($value) || $value === null) {
            $entity->{$relation->property} = $value;

            return;
        }
        if ($relation->joinColumn === null) {
            throw new OrmException("\${$relation->property} is the inverse side of a to-one relation: it has no foreign key to point at id " . var_export($value, true) . '; assign an entity, or set the relation on the owning side.');
        }
        // bare id ({ owner: 5 }, like TypeORM's { owner: { id: 5 } }): becomes the foreign key; a `= null` default
        // or a loaded entity is dropped (uninitialized = "not loaded") so the id is what save() writes
        $this->state->setPendingForeignKey($entity, $relation->joinColumn, $value);
        if ((new ReflectionProperty($entity, $relation->property))->isInitialized($entity)) {
            unset($entity->{$relation->property});
        }
        $column = MetadataRegistry::get($entity::class)->columns[$relation->joinColumn] ?? null;
        if ($column?->property !== null) {
            $this->assign($entity, $column, $value);
        }
    }

    public static function allowsNull(object $entity, string $property): bool
    {
        $type = (new ReflectionProperty($entity, $property))->getType();

        return $type === null || $type->allowsNull();
    }

    public static function propertyType(object|string $entity, string $property): ?string
    {
        $type = (new ReflectionProperty($entity, $property))->getType();

        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }
}
