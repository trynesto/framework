<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Nesto\Orm\Mapping\RelationMetadata;
use Nesto\Orm\Mapping\TypeConverter;
use ReflectionClass;
use ReflectionProperty;
use WeakReference;

/**
 * `lazy: true` relations (TypeORM's Promise<T> relations, PHP 8.4+ native lazy objects). Every hydrated
 * entity gets, for each lazy relation that the query did not load:
 *
 *   to-one (owning side)  a lazy GHOST of the target whose referenced key is already set: reading
 *                         `$post->author->id` costs nothing, reading any other property loads the row once
 *                         (soft-deleted rows included; a missing row throws EntityNotFoundException);
 *                         a NULL foreign key leaves the property null
 *   to-many               a LazyCollection loaded on first iteration / count / index / toArray()
 *
 * Loading `relations: ['author']` (or a join) replaces the placeholder with the real value. Initializers only
 * keep the DataSource (which reconnects) and the key / a weak reference to the owner: nothing ties a
 * placeholder to a request or a connection beyond the entity's own lifetime (worker mode).
 */
final class LazyRelations
{
    public function __construct(private readonly DataSource $dataSource) {}

    public function install(EntityMetadata $meta, object $entity): void
    {
        foreach ($meta->relations as $relation) {
            if ($relation->lazy) {
                $relation->isToMany() ? $this->installCollection($meta, $entity, $relation) : $this->installGhost($meta, $entity, $relation);
            }
        }
    }

    private function installCollection(EntityMetadata $meta, object $entity, RelationMetadata $relation): void
    {
        $dataSource = $this->dataSource;
        $owner = WeakReference::create($entity);
        $class = $meta->class;
        $property = $relation->property;
        $entity->{$property} = new LazyCollection(static function () use ($dataSource, $owner, $class, $property): array {
            $entity = $owner->get() ?? throw new OrmException("The $class owning this lazy \$$property is gone.");

            return $dataSource->broadcaster()->loading(static function () use ($dataSource, $entity, $class, $property): array {
                $placeholder = $entity->{$property};
                (new RelationLoader($dataSource))->load(MetadataRegistry::get($class), [$entity], [$property]);
                $items = $entity->{$property};
                $entity->{$property} = $placeholder;

                return is_array($items) ? $items : [];
            });
        });
        $this->dataSource->state()->touchRelation($entity, $property);
    }

    private function installGhost(EntityMetadata $meta, object $entity, RelationMetadata $relation): void
    {
        $fk = $this->dataSource->hydrator()->foreignKeyValue($meta, $entity, $relation);
        if ($fk === null) {
            return;
        }
        $targetMeta = MetadataRegistry::get($relation->target);
        $keyColumn = $targetMeta->column($relation->referencedColumn) ?? $targetMeta->primary;
        $dataSource = $this->dataSource;
        $class = $targetMeta->class;
        $columnName = $keyColumn->name;
        $ghost = (new ReflectionClass($class))->newLazyGhost(static function (object $ghost) use ($dataSource, $class, $columnName, $fk): void {
            self::initialize($dataSource, $ghost, $class, $columnName, $fk);
        });
        if ($keyColumn->property !== null) {
            (new ReflectionProperty($class, $keyColumn->property))->setRawValueWithoutLazyInitialization($ghost, TypeConverter::toPhp($keyColumn, $fk));
        }
        $entity->{$relation->property} = $ghost;
    }

    /** Loads the row of a ghost (on first access to one of its other properties). */
    private static function initialize(DataSource $dataSource, object $ghost, string $class, string $column, mixed $key): void
    {
        $meta = MetadataRegistry::get($class);
        $conn = $dataSource->readConnection();
        $row = $conn->fetchOne('SELECT * FROM ' . $conn->quote($meta->table) . ' WHERE ' . $conn->quote($column) . ' = :key', ['key' => $key]);
        if ($row === null) {
            throw EntityNotFoundException::for($class, [$column => $key]);
        }
        $dataSource->hydrator()->hydrate($meta, $row, '', $ghost);
        (new RelationIdLoader($dataSource))->load($meta, [$ghost]);
    }
}
