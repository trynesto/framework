<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Closure;
use Nesto\Orm\Mapping\RelationMetadata;
use Nesto\Orm\Mapping\RelationType;
use Nesto\Orm\Mapping\TypeConverter;

/**
 * Relation ids and counts without loading the related entities, ONE query per relation for a batch:
 *
 *   #[RelationId('tags')] properties          filled on every load (find*, QueryBuilder, relations, lazy)
 *   find(['loadRelationIds' => true])         relation properties replaced by their ids
 *   $qb->loadRelationIdAndMap('p.tagIds', 'p.tags') / loadRelationCountAndMap('u.postCount', 'u.posts')
 *
 * Owning to-one ids come from the loaded foreign keys (no query); OneToMany / inverse OneToOne read the
 * target table, ManyToMany only the join table.
 */
final class RelationIdLoader
{
    public function __construct(private readonly DataSource $dataSource) {}

    /** Fill the #[RelationId] properties of hydrated entities. @param list<object> $entities */
    public function load(EntityMetadata $meta, array $entities): void
    {
        if ($meta->relationIds === [] || $entities === []) {
            return;
        }
        foreach ($meta->relationIds as $property => $relationName) {
            $relation = $meta->relations[$relationName];
            $ids = $this->ids($meta, $relation, $entities);
            foreach ($entities as $entity) {
                self::assign($entity, $property, $ids[spl_object_id($entity)]);
            }
        }
    }

    /**
     * Id (to-one, null when none) or list of ids (to-many) per entity, keyed by spl_object_id().
     * With $condition the related rows are filtered by a QueryBuilder callback (target alias $alias).
     *
     * @param list<object> $entities
     * @return array<int, mixed>
     */
    public function ids(EntityMetadata $meta, RelationMetadata $relation, array $entities, ?Closure $condition = null, string $alias = 'r'): array
    {
        $out = [];
        if ($relation->holdsForeignKey() && $condition === null) {
            $targetMeta = MetadataRegistry::get($relation->target);
            $keyColumn = $targetMeta->column($relation->referencedColumn) ?? $targetMeta->primary;
            foreach ($entities as $entity) {
                $fk = $this->dataSource->hydrator()->foreignKeyValue($meta, $entity, $relation);
                $out[spl_object_id($entity)] = $fk === null ? null : TypeConverter::toPhp($keyColumn, $fk);
            }

            return $out;
        }
        $targetMeta = MetadataRegistry::get($relation->target);
        $rows = $this->relatedRows($meta, $relation, $entities, $condition, false, $alias);
        $byParent = [];
        foreach ($rows as $row) {
            $byParent[(string) $row['nesto_parent']][] = TypeConverter::toPhp($targetMeta->primary, $row['nesto_related']);
        }
        foreach ($entities as $entity) {
            $key = $this->parentKey($meta, $relation, $entity);
            $ids = $key === null ? [] : ($byParent[(string) $key] ?? []);
            $out[spl_object_id($entity)] = $relation->isToMany() ? $ids : ($ids[0] ?? null);
        }

        return $out;
    }

    /**
     * Number of related rows per entity, keyed by spl_object_id().
     *
     * @param list<object> $entities
     * @return array<int, int>
     */
    public function counts(EntityMetadata $meta, RelationMetadata $relation, array $entities, ?Closure $condition = null, string $alias = 'r'): array
    {
        $byParent = [];
        foreach ($this->relatedRows($meta, $relation, $entities, $condition, true, $alias) as $row) {
            $byParent[(string) $row['nesto_parent']] = (int) $row['nesto_related'];
        }
        $out = [];
        foreach ($entities as $entity) {
            $key = $this->parentKey($meta, $relation, $entity);
            $out[spl_object_id($entity)] = $key === null ? 0 : ($byParent[(string) $key] ?? 0);
        }

        return $out;
    }

    /** @return list<array{nesto_parent: mixed, nesto_related: mixed}> parent key + related id (or COUNT) */
    private function relatedRows(EntityMetadata $meta, RelationMetadata $relation, array $entities, ?Closure $condition, bool $count, string $alias): array
    {
        $keys = [];
        foreach ($entities as $entity) {
            $key = $this->parentKey($meta, $relation, $entity);
            if ($key !== null) {
                $keys[(string) $key] = $key;
            }
        }
        if ($keys === []) {
            return [];
        }
        $targetMeta = MetadataRegistry::get($relation->target);
        $qb = new QueryBuilder($this->dataSource, $targetMeta->class, $alias);
        $pk = $alias . '.' . $targetMeta->primary->name;
        if ($relation->type->usesPivot()) {
            [$joinTable, $ownerColumn, $targetColumn] = QueryBuilder::joinTableFor($relation, $meta, $targetMeta);
            $qb->innerJoin($joinTable, 'nesto_jt', 'nesto_jt.' . $targetColumn . ' = ' . $pk);
            $parent = 'nesto_jt.' . $ownerColumn;
            if ($relation->type->isMorph()) {
                $qb->andWhere('nesto_jt.' . $relation->morphTypeColumn . ' = :nesto_morph_type', ['nesto_morph_type' => MorphMap::aliasFor((string) $relation->morphClass)]);
            }
            if ($condition === null && ! $count) {
                $qb->withDeleted(); // ids straight from the join table, like TypeORM
            }
        } elseif ($relation->holdsForeignKey()) {
            // to-one owning side with a condition / count: the target row referenced by the FK
            $parentMeta = $meta;
            $qb->innerJoin($parentMeta->table, 'nesto_owner', 'nesto_owner.' . $relation->joinColumn . ' = ' . $alias . '.' . $targetMeta->columnName($relation->referencedColumn));
            $parent = 'nesto_owner.' . $parentMeta->primary->name;
        } elseif ($relation->type === RelationType::OneToMany || $relation->type === RelationType::OneToOne) {
            $inverse = $relation->inverseSide !== null ? $targetMeta->relation($relation->inverseSide) : null;
            if ($inverse?->joinColumn === null) {
                throw new OrmException("Relation {$meta->class}::\${$relation->property} needs an inverseSide pointing at a ManyToOne/OneToOne with a join column on {$targetMeta->class}.");
            }
            $parent = $alias . '.' . $inverse->joinColumn;
        } else {
            throw new OrmException("Relation ids / counts support ManyToOne, OneToOne, OneToMany and ManyToMany relations; {$meta->class}::\${$relation->property} is a {$relation->type->value}.");
        }
        $qb->andWhere([$parent => ['In', array_values($keys)]]);
        if ($condition !== null) {
            $condition($qb);
        }
        $qb->select([$parent . ' AS nesto_parent', ($count ? 'COUNT(DISTINCT ' . $pk . ')' : $pk) . ' AS nesto_related']);
        if ($count) {
            $qb->groupBy($parent);
        } else {
            $qb->orderBy($pk);
        }

        return $qb->getRawMany();
    }

    /** The value the related rows store to point at $entity. */
    private function parentKey(EntityMetadata $meta, RelationMetadata $relation, object $entity): mixed
    {
        $hydrator = $this->dataSource->hydrator();
        if ($relation->type->usesPivot() || $relation->holdsForeignKey()) {
            $pk = $meta->primaryValue($entity);

            return $pk === null ? null : TypeConverter::toDatabase($meta->primary, $pk);
        }
        $inverse = MetadataRegistry::get($relation->target)->relation((string) $relation->inverseSide);

        return $hydrator->columnValue($meta, $entity, $meta->columnName($inverse?->referencedColumn ?? (string) $meta->primary->property));
    }

    /** Assign a relation id / count / list, explaining a property type that cannot hold it. */
    public static function assign(object $entity, string $property, mixed $value): void
    {
        try {
            $entity->{$property} = $value;
        } catch (\TypeError $e) {
            $type = (string) (new \ReflectionProperty($entity, $property))->getType();
            throw new OrmException($entity::class . "::\$$property ($type) cannot hold the relation id(s) " . json_encode($value) . ': widen its type (e.g. int|User, ?int, array).', 0, $e);
        }
    }
}
