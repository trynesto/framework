<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Nesto\Orm\Mapping\ColumnMetadata;
use Nesto\Orm\Mapping\RelationMetadata;
use Nesto\Orm\Mapping\RelationType;
use Nesto\Orm\Mapping\ThroughPath;
use Nesto\Orm\Mapping\TypeConverter;

/**
 * Eager-loads relations for a set of already-hydrated entities with ONE query per relation
 * level (no N+1) — one query per related class for a MorphTo. Supports nested paths such as
 * "posts.tags" or "comments.commentable" through every relation type.
 */
final class RelationLoader
{
    /** @var list<string>|null columns of the relation being loaded (null: all), see columnsFor() */
    private ?array $columns = null;

    public function __construct(private readonly DataSource $dataSource) {}

    /**
     * @param list<object> $entities
     * @param list<string> $relations relation names / dotted paths
     * @param array<string, mixed> $select the `select` find option for this level (['author' => ['name' => true]]):
     *                                     a relation whose subtree names columns only loads those (plus keys)
     */
    public function load(EntityMetadata $meta, array $entities, array $relations, bool $withDeleted = false, array $select = []): void
    {
        if ($entities === [] || $relations === []) {
            return;
        }
        $grouped = [];
        foreach ($relations as $path) {
            [$first, $rest] = array_pad(explode('.', $path, 2), 2, null);
            $grouped[$first] ??= [];
            if ($rest !== null) {
                $grouped[$first][] = $rest;
            }
        }
        foreach ($grouped as $name => $nested) {
            $relation = $meta->relation($name) ?? throw new OrmException("Unknown relation \"$name\" on {$meta->class}.");
            $sub = is_array($select[$name] ?? null) ? $select[$name] : [];
            $columns = $relation->type === RelationType::MorphTo ? null : self::columnsFor(MetadataRegistry::get($relation->target), $relation, $sub, $nested);
            $children = $this->loadOne($meta, $relation, $entities, $withDeleted, $columns);
            $byClass = [];
            foreach ($children as $child) {
                $byClass[$child::class][] = $child;
            }
            foreach ($byClass as $class => $group) {
                (new RelationIdLoader($this->dataSource))->load(MetadataRegistry::get($class), $group);
            }
            if ($nested !== []) {
                $this->loadNested($relation, $children, $nested, $withDeleted, $sub);
            }
        }
    }

    /** @param list<object> $children @param list<string> $nested */
    private function loadNested(RelationMetadata $relation, array $children, array $nested, bool $withDeleted, array $select = []): void
    {
        if ($relation->type !== RelationType::MorphTo) {
            $this->load(MetadataRegistry::get($relation->target), $children, $nested, $withDeleted, $select);

            return;
        }
        // Polymorphic children: load per class. A nested relation only has to exist on some of the classes
        // (["commentable.author"] loads the author of posts and skips videos that have none).
        $byClass = [];
        foreach ($children as $child) {
            $byClass[$child::class][] = $child;
        }
        $found = [];
        foreach ($byClass as $class => $group) {
            $childMeta = MetadataRegistry::get($class);
            $paths = [];
            foreach ($nested as $path) {
                $first = explode('.', $path, 2)[0];
                if ($childMeta->relation($first) !== null) {
                    $paths[] = $path;
                    $found[$first] = true;
                }
            }
            $this->load($childMeta, $group, $paths, $withDeleted);
        }
        foreach ($nested as $path) {
            $first = explode('.', $path, 2)[0];
            if ($byClass !== [] && ! isset($found[$first])) {
                throw new OrmException("Unknown relation \"$first\" on the entities loaded by MorphTo \${$relation->property} (" . implode(', ', array_keys($byClass)) . ').');
            }
        }
    }

    /** @param list<object> $entities @return list<object> the loaded related entities (unique) */
    private function loadOne(EntityMetadata $meta, RelationMetadata $relation, array $entities, bool $withDeleted, ?array $columns = null): array
    {
        $this->columns = $columns;
        try {
            return match ($relation->type) {
                RelationType::ManyToOne => $this->loadToOne($meta, $relation, MetadataRegistry::get($relation->target), $entities, $withDeleted),
                RelationType::OneToOne => $relation->joinColumn !== null
                    ? $this->loadToOne($meta, $relation, MetadataRegistry::get($relation->target), $entities, $withDeleted)
                    : $this->loadInverse($meta, $relation, MetadataRegistry::get($relation->target), $entities, $withDeleted),
                RelationType::OneToMany => $this->loadInverse($meta, $relation, MetadataRegistry::get($relation->target), $entities, $withDeleted),
                RelationType::ManyToMany, RelationType::MorphToMany, RelationType::MorphedByMany => $this->loadManyToMany($meta, $relation, MetadataRegistry::get($relation->target), $entities, $withDeleted),
                RelationType::MorphTo => $this->loadMorphTo($meta, $relation, $entities, $withDeleted),
                RelationType::MorphOne, RelationType::MorphMany => $this->loadMorphMany($meta, $relation, MetadataRegistry::get($relation->target), $entities, $withDeleted),
                RelationType::HasOneThrough, RelationType::HasManyThrough, RelationType::BelongsToThrough => $this->loadThrough($meta, $relation, $entities, $withDeleted),
            };
        } finally {
            $this->columns = null;
        }
    }

    /**
     * Columns to load for a relation whose `select` subtree names columns (null: all of them): the named ones,
     * the primary key, every foreign-key / morph column, and the keys the parent and nested relations join on.
     *
     * @param array<string, mixed> $select
     * @param list<string>         $nested nested relation paths
     * @return list<string>|null
     */
    public static function columnsFor(EntityMetadata $meta, ?RelationMetadata $parentRelation, array $select, array $nested): ?array
    {
        $named = array_map(fn (ColumnMetadata $c) => $c->name, FindOptions::selectedColumns($meta, $select));
        if ($named === []) {
            return null;
        }
        if ($parentRelation?->holdsForeignKey()) {
            $named[] = $meta->columnName($parentRelation->referencedColumn);
        }
        foreach ($nested as $path) {
            $relation = $meta->relation(explode('.', $path, 2)[0]);
            if ($relation === null || $relation->holdsForeignKey() || $relation->type === RelationType::MorphTo) {
                continue;
            }
            $named[] = match (true) {
                $relation->type === RelationType::OneToMany || $relation->type === RelationType::OneToOne
                    => $meta->columnName(MetadataRegistry::get($relation->target)->relation((string) $relation->inverseSide)?->referencedColumn ?? (string) $meta->primary->property),
                $relation->type === RelationType::MorphOne || $relation->type === RelationType::MorphMany
                    => $meta->columnName($relation->localKey ?? (string) $meta->primary->property),
                $relation->isThrough() => ThroughPath::for($meta, $relation)->parentKey,
                default => $meta->primary->name,
            };
        }
        $out = [];
        foreach ($meta->columns as $column) {
            if ($column->primary || $column->relation !== null || $column->special === 'discriminator' || in_array($column->name, $named, true)) {
                $out[] = $column->name;
            }
        }

        return $out;
    }

    /** ManyToOne / owning OneToOne: WHERE target.pk IN (fk values). */
    private function loadToOne(EntityMetadata $meta, RelationMetadata $relation, EntityMetadata $targetMeta, array $entities, bool $withDeleted): array
    {
        $hydrator = $this->dataSource->hydrator();
        $fks = [];
        foreach ($entities as $entity) {
            $fk = $hydrator->foreignKeyValue($meta, $entity, $relation);
            $fks[$entity::class . spl_object_id($entity)] = $fk;
        }
        $ids = array_values(array_unique(array_filter($fks, fn ($v) => $v !== null)));
        $refColumn = $targetMeta->columnName($relation->referencedColumn);
        $map = [];
        if ($ids !== []) {
            $rows = $this->fetch($targetMeta, $refColumn . ' IN (:ids)', ['ids' => $ids], $withDeleted);
            foreach ($rows as $row) {
                $map[(string) $row[$refColumn]] = $hydrator->hydrate($targetMeta, $row);
            }
        }
        foreach ($entities as $entity) {
            $fk = $fks[$entity::class . spl_object_id($entity)];
            $related = $fk === null ? null : ($map[(string) $fk] ?? null);
            if ($related !== null || Hydrator::allowsNull($entity, $relation->property)) {
                $entity->{$relation->property} = $related;
            }
        }

        return array_values($map);
    }

    /** OneToMany / inverse OneToOne: WHERE target.fk IN (parent ids). */
    private function loadInverse(EntityMetadata $meta, RelationMetadata $relation, EntityMetadata $targetMeta, array $entities, bool $withDeleted): array
    {
        $inverse = $relation->inverseSide !== null ? $targetMeta->relation($relation->inverseSide) : null;
        if ($inverse?->joinColumn === null) {
            throw new OrmException("Relation {$meta->class}::\${$relation->property} needs an inverseSide pointing at a ManyToOne/OneToOne with a join column on {$targetMeta->class}.");
        }
        $refColumn = $meta->column($inverse->referencedColumn) ?? $meta->primary;

        return $this->loadByKey($meta, $relation, $targetMeta, $entities, $withDeleted, $refColumn->name, $inverse->joinColumn);
    }

    /** MorphOne / MorphMany: WHERE target.{name}_id IN (parent ids) AND target.{name}_type = alias. */
    private function loadMorphMany(EntityMetadata $meta, RelationMetadata $relation, EntityMetadata $targetMeta, array $entities, bool $withDeleted): array
    {
        return $this->loadByKey(
            $meta, $relation, $targetMeta, $entities, $withDeleted,
            $meta->columnName($relation->localKey ?? (string) $meta->primary->property),
            (string) $relation->morphIdColumn,
            [(string) $relation->morphTypeColumn => MorphMap::aliasFor((string) $relation->morphClass)],
        );
    }

    /**
     * Children whose $childColumn holds the parent's $parentColumn value, grouped back onto the parents.
     *
     * @param array<string, mixed> $extra additional column = value conditions on the children
     * @return list<object>
     */
    private function loadByKey(EntityMetadata $meta, RelationMetadata $relation, EntityMetadata $targetMeta, array $entities, bool $withDeleted, string $parentColumn, string $childColumn, array $extra = []): array
    {
        $hydrator = $this->dataSource->hydrator();
        $keyColumn = $targetMeta->columns[$childColumn] ?? null;
        $keys = [];
        foreach ($entities as $i => $entity) {
            $keys[$i] = $hydrator->columnValue($meta, $entity, $parentColumn);
            if ($relation->isToMany()) {
                $entity->{$relation->property} = [];
            } elseif (Hydrator::allowsNull($entity, $relation->property)) {
                $entity->{$relation->property} = null;
            }
        }
        $ids = array_values(array_unique(array_map(fn ($k) => $keyColumn?->morph !== null ? Hydrator::morphKey($keyColumn, $k) : $k, array_filter($keys, fn ($k) => $k !== null))));
        if ($ids === []) {
            return [];
        }
        $conn = $this->dataSource->readConnection();
        $where = $childColumn . ' IN (:ids)';
        $params = ['ids' => $ids];
        $i = 0;
        foreach ($extra as $column => $value) {
            $where .= ' AND ' . $conn->quote($column) . ' = :extra' . $i;
            $params['extra' . $i++] = $value;
        }
        $byParent = [];
        $loaded = [];
        foreach ($this->fetch($targetMeta, $where, $params, $withDeleted) as $row) {
            $child = $hydrator->hydrate($targetMeta, $row);
            $byParent[(string) $row[$childColumn]][] = $child;
            $loaded[] = $child;
        }
        $state = $this->dataSource->state();
        foreach ($entities as $i => $entity) {
            $children = $keys[$i] === null ? [] : ($byParent[(string) $keys[$i]] ?? []);
            if ($relation->isToMany()) {
                $entity->{$relation->property} = $children;
            } elseif ($children !== []) {
                $entity->{$relation->property} = $children[0];
            }
            $state->setRelationIds($entity, $relation->property, array_map(fn (object $c) => TypeConverter::toDatabase($targetMeta->primary, $targetMeta->primaryValue($c)), $relation->isToMany() ? $children : array_slice($children, 0, 1)));
        }

        return $loaded;
    }

    /** MorphTo: group the rows by type, then ONE query per related class: WHERE pk IN (ids of that type). */
    private function loadMorphTo(EntityMetadata $meta, RelationMetadata $relation, array $entities, bool $withDeleted): array
    {
        foreach ($relation->morphTypes as $class) {
            MetadataRegistry::get($class); // registers their #[MorphAlias]
        }
        $hydrator = $this->dataSource->hydrator();
        $pointers = [];
        $idsByType = [];
        foreach ($entities as $i => $entity) {
            [$type, $id] = $hydrator->morphValues($meta, $entity, $relation);
            if ($type === null || $id === null) {
                $pointers[$i] = null;
                continue;
            }
            $pointers[$i] = [$type, (string) $id];
            $idsByType[$type][(string) $id] = $id;
        }
        $map = [];
        $loaded = [];
        foreach ($idsByType as $type => $ids) {
            $targetMeta = MetadataRegistry::get(MorphMap::classFor($type));
            $ids = array_values(array_map(fn ($id) => TypeConverter::toDatabase($targetMeta->primary, $id), $ids));
            foreach ($this->fetch($targetMeta, $targetMeta->primary->name . ' IN (:ids)', ['ids' => $ids], $withDeleted) as $row) {
                $related = $hydrator->hydrate($targetMeta, $row);
                $map[$type][(string) $row[$targetMeta->primary->name]] = $related;
                $loaded[] = $related;
            }
        }
        foreach ($entities as $i => $entity) {
            $related = $pointers[$i] === null ? null : ($map[$pointers[$i][0]][$pointers[$i][1]] ?? null);
            if ($related !== null || Hydrator::allowsNull($entity, $relation->property)) {
                $entity->{$relation->property} = $related;
            }
        }

        return $loaded;
    }

    /** ManyToMany (either side), MorphToMany, MorphedByMany: one query joining the pivot table. */
    private function loadManyToMany(EntityMetadata $meta, RelationMetadata $relation, EntityMetadata $targetMeta, array $entities, bool $withDeleted): array
    {
        [$joinTable, $ownerColumn, $targetColumn] = QueryBuilder::joinTableFor($relation, $meta, $targetMeta);
        $conn = $this->dataSource->readConnection();
        $hydrator = $this->dataSource->hydrator();
        $state = $this->dataSource->state();
        $ids = [];
        foreach ($entities as $entity) {
            $ids[] = TypeConverter::toDatabase($meta->primary, $meta->primaryValue($entity));
            $entity->{$relation->property} = [];
        }
        $ids = array_values(array_unique($ids));
        $params = ['ids' => $ids];
        $q = fn (string $s) => $conn->quote($s);
        $sql = 'SELECT ' . $this->targetColumns('t') . ', ' . $q('jt') . '.' . $q($ownerColumn) . ' AS ' . $q('__nesto_parent')
            . ' FROM ' . $q($joinTable) . ' AS ' . $q('jt')
            . ' INNER JOIN ' . $q($targetMeta->table) . ' AS ' . $q('t') . ' ON ' . $q('t') . '.' . $q($targetMeta->primary->name) . ' = ' . $q('jt') . '.' . $q($targetColumn)
            . ' WHERE ' . $q('jt') . '.' . $q($ownerColumn) . ' IN (:ids)';
        if ($relation->type->isMorph()) {
            $sql .= ' AND ' . $q('jt') . '.' . $q((string) $relation->morphTypeColumn) . ' = :morph_type';
            $params['morph_type'] = MorphMap::aliasFor((string) $relation->morphClass);
        }
        if ($targetMeta->hasSoftDelete() && ! $withDeleted) {
            $sql .= ' AND ' . $q('t') . '.' . $q($targetMeta->deletedAtColumn()->name) . ' IS NULL';
        }
        $sql .= self::discriminatorSql($conn, $targetMeta, 't', $params);
        $sql .= ' ORDER BY ' . $q('t') . '.' . $q($targetMeta->primary->name);
        $identity = [];
        $byParent = [];
        foreach ($conn->fetchAll($sql, $params) as $row) {
            $parentKey = (string) $row['__nesto_parent'];
            unset($row['__nesto_parent']);
            $pk = (string) $row[$targetMeta->primary->name];
            $identity[$pk] ??= $hydrator->hydrate($targetMeta, $row);
            $byParent[$parentKey][] = $identity[$pk];
        }
        foreach ($entities as $entity) {
            $key = (string) TypeConverter::toDatabase($meta->primary, $meta->primaryValue($entity));
            $children = $byParent[$key] ?? [];
            $entity->{$relation->property} = $children;
            $state->setRelationIds($entity, $relation->property, array_map(fn (object $c) => TypeConverter::toDatabase($targetMeta->primary, $targetMeta->primaryValue($c)), $children));
        }

        return array_values($identity);
    }

    /** HasOneThrough / HasManyThrough / BelongsToThrough: one query joining the intermediate tables. */
    private function loadThrough(EntityMetadata $meta, RelationMetadata $relation, array $entities, bool $withDeleted): array
    {
        $path = ThroughPath::for($meta, $relation);
        $targetMeta = $path->target();
        $conn = $this->dataSource->readConnection();
        $hydrator = $this->dataSource->hydrator();
        $q = fn (string $s) => $conn->quote($s);
        $keys = [];
        foreach ($entities as $i => $entity) {
            $keys[$i] = $hydrator->columnValue($meta, $entity, $path->parentKey);
            if ($relation->isToMany()) {
                $entity->{$relation->property} = [];
            } elseif (Hydrator::allowsNull($entity, $relation->property)) {
                $entity->{$relation->property} = null;
            }
        }
        $ids = array_values(array_unique(array_filter($keys, fn ($k) => $k !== null)));
        if ($ids === []) {
            return [];
        }
        $last = count($path->hops) - 1;
        $alias = fn (int $i) => $i === $last ? 't' : 'h' . $i;
        $sql = 'SELECT ' . $this->targetColumns('t') . ', ' . $q('h0') . '.' . $q($path->hops[0]['in']) . ' AS ' . $q('__nesto_parent')
            . ' FROM ' . $q($path->hops[0]['meta']->table) . ' AS ' . $q('h0');
        $conditions = [];
        foreach ($path->hops as $i => $hop) {
            if ($i > 0) {
                $prev = $path->hops[$i - 1];
                $sql .= ' INNER JOIN ' . $q($hop['meta']->table) . ' AS ' . $q($alias($i))
                    . ' ON ' . $q($alias($i)) . '.' . $q($hop['in']) . ' = ' . $q($alias($i - 1)) . '.' . $q((string) $prev['out']);
            }
            if ($hop['meta']->hasSoftDelete() && ! $withDeleted) {
                $conditions[] = $q($alias($i)) . '.' . $q($hop['meta']->deletedAtColumn()->name) . ' IS NULL';
            }
        }
        $sql .= ' WHERE ' . $q('h0') . '.' . $q($path->hops[0]['in']) . ' IN (:ids)';
        foreach ($conditions as $condition) {
            $sql .= ' AND ' . $condition;
        }
        $params = ['ids' => $ids];
        $sql .= self::discriminatorSql($conn, $targetMeta, 't', $params);
        $sql .= ' ORDER BY ' . $q('t') . '.' . $q($targetMeta->primary->name);

        $identity = [];
        $byParent = [];
        foreach ($conn->fetchAll($sql, $params) as $row) {
            $parentKey = (string) $row['__nesto_parent'];
            unset($row['__nesto_parent']);
            $pk = (string) $row[$targetMeta->primary->name];
            $identity[$pk] ??= $hydrator->hydrate($targetMeta, $row);
            if (! in_array($identity[$pk], $byParent[$parentKey] ?? [], true)) {
                $byParent[$parentKey][] = $identity[$pk];
            }
        }
        foreach ($entities as $i => $entity) {
            $children = $keys[$i] === null ? [] : ($byParent[(string) $keys[$i]] ?? []);
            if ($relation->isToMany()) {
                $entity->{$relation->property} = $children;
            } elseif ($children !== []) {
                $entity->{$relation->property} = $children[0];
            }
        }

        return array_values($identity);
    }

    private function targetColumns(string $alias): string
    {
        $conn = $this->dataSource->readConnection();
        if ($this->columns === null) {
            return $conn->quote($alias) . '.*';
        }

        return implode(', ', array_map(fn (string $c) => $conn->quote($alias) . '.' . $conn->quote($c), $this->columns));
    }

    /** @return list<array<string, mixed>> */
    private function fetch(EntityMetadata $targetMeta, string $where, array $params, bool $withDeleted): array
    {
        $conn = $this->dataSource->readConnection();
        $where = (string) preg_replace_callback('/^([A-Za-z_][A-Za-z0-9_]*) IN/', fn ($m) => $conn->quote($m[1]) . ' IN', $where);
        $sql = 'SELECT ' . ($this->columns === null ? '*' : implode(', ', array_map($conn->quote(...), $this->columns))) . ' FROM ' . $conn->quote($targetMeta->table) . ' WHERE ' . $where;
        if ($targetMeta->hasSoftDelete() && ! $withDeleted) {
            $sql .= ' AND ' . $conn->quote($targetMeta->deletedAtColumn()->name) . ' IS NULL';
        }
        $sql .= self::discriminatorSql($conn, $targetMeta, null, $params);
        $sql .= ' ORDER BY ' . $conn->quote($targetMeta->primary->name);

        return $conn->fetchAll($sql, $params);
    }

    /**
     * " AND type IN (…)" when $meta is a single table inheritance child (its relations only load rows of its
     * type); empty otherwise.
     *
     * @param array<string, mixed> $params receives the values
     */
    public static function discriminatorSql(Connection $conn, EntityMetadata $meta, ?string $alias, array &$params): string
    {
        $values = $meta->discriminatorValues();
        if ($values === null) {
            return '';
        }
        $params['nesto_discriminator'] = $values;

        return ' AND ' . ($alias !== null ? $conn->quote($alias) . '.' : '') . $conn->quote((string) $meta->discriminatorColumn) . ' IN (:nesto_discriminator)';
    }
}
