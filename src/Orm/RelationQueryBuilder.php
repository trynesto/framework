<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Nesto\Orm\Mapping\RelationMetadata;
use Nesto\Orm\Mapping\RelationType;
use Nesto\Orm\Mapping\TypeConverter;

/**
 * TypeORM's RelationQueryBuilder: works on ONE relation of entities given by object or id, without loading
 * them. Writes go straight to the foreign key / join table (no cascades, no orphanedRowAction).
 *
 *   $ds->createQueryBuilder()->relation(Post::class, 'tags')->of($post)->add($tag);        // join-table row
 *   ->relation(Post::class, 'tags')->of(1)->remove([2, 3]);
 *   ->relation(Post::class, 'tags')->of($post)->addAndRemove([$php], [$sql]);
 *   ->relation(Post::class, 'author')->of([1, 2])->set($user);                              // many-to-one FK
 *   ->relation(User::class, 'posts')->of($user)->add($post);                                // post.user_id = user
 *   ->relation(User::class, 'profile')->of($user)->set(null);                               // inverse one-to-one
 *   ->relation(Post::class, 'author')->of($post)->loadOne();  ->relation(User::class, 'posts')->of($user)->loadMany();
 *
 * set() is for many-to-one / one-to-one, add() / remove() / addAndRemove() for one-to-many / many-to-many
 * (TypeORM's rules). Values and `of` accept entities, primary-key values or ['id' => 5] maps.
 */
final class RelationQueryBuilder
{
    private readonly EntityMetadata $meta;
    private readonly RelationMetadata $relation;
    private readonly EntityMetadata $targetMeta;
    /** @var list<mixed> */
    private array $of = [];
    private bool $ofSet = false;

    /** @param class-string $entity */
    public function __construct(private readonly DataSource $dataSource, string $entity, string $property)
    {
        $this->meta = MetadataRegistry::get($entity);
        $this->relation = $this->meta->relation($property) ?? throw new OrmException("Unknown relation \"$property\" on {$this->meta->class}.");
        if ($this->relation->type === RelationType::MorphTo) {
            throw new OrmException("relation() does not support MorphTo {$this->meta->class}::\$$property (its target depends on the row).");
        }
        $this->targetMeta = MetadataRegistry::get($this->relation->target);
    }

    /** The entity (or entities) whose relation is changed / loaded: objects, ids or ['id' => ...] maps. */
    public function of(mixed $entityOrIds): self
    {
        $this->of = is_array($entityOrIds) && array_is_list($entityOrIds) ? $entityOrIds : [$entityOrIds];
        $this->ofSet = true;

        return $this;
    }

    /** Many-to-one / one-to-one: point the relation at $value (entity, id or null). */
    public function set(mixed $value): void
    {
        $type = $this->relation->type;
        if ($this->relation->isToMany()) {
            throw new OrmException("Set operation is only supported for many-to-one and one-to-one relations. However given \"{$this->relation->property}\" has {$type->value} relation. Use .add() method instead.");
        }
        $this->assertWritable();
        $conn = $this->dataSource->getConnection();
        $q = $conn->quote(...);
        if ($this->relation->holdsForeignKey()) {
            $ids = $this->ofIds();
            $fk = $value === null ? null : $this->targetKey($value);
            $conn->execute(
                'UPDATE ' . $q($this->meta->table) . ' SET ' . $q((string) $this->relation->joinColumn) . ' = :fk WHERE ' . $q($this->meta->primary->name) . ' IN (:ids)',
                ['fk' => $fk, 'ids' => $ids],
            );

            return;
        }
        // inverse one-to-one: the target row holds the key; release the previous one first (unique key)
        $inverse = $this->inverse();
        $key = $this->singleOfKey($inverse);
        $table = $q($this->targetMeta->table);
        $fkColumn = $q((string) $inverse->joinColumn);
        $pk = $q($this->targetMeta->primary->name);
        if ($value === null) {
            $conn->execute("UPDATE $table SET $fkColumn = NULL WHERE $fkColumn = :key", ['key' => $key]);

            return;
        }
        $targetId = $this->idOf($this->targetMeta, $value);
        $conn->execute("UPDATE $table SET $fkColumn = NULL WHERE $fkColumn = :key AND $pk <> :id", ['key' => $key, 'id' => $targetId]);
        $conn->execute("UPDATE $table SET $fkColumn = :key WHERE $pk = :id", ['key' => $key, 'id' => $targetId]);
    }

    /** One-to-many / many-to-many: attach $values (entity / id or a list). */
    public function add(mixed $values): void
    {
        $this->assertToMany('Add', 'set');
        $values = $this->list($values);
        if ($values === []) {
            return;
        }
        $conn = $this->dataSource->getConnection();
        $q = $conn->quote(...);
        if ($this->relation->type === RelationType::OneToMany) {
            $inverse = $this->inverse();
            $conn->execute(
                'UPDATE ' . $q($this->targetMeta->table) . ' SET ' . $q((string) $inverse->joinColumn) . ' = :key WHERE ' . $q($this->targetMeta->primary->name) . ' IN (:ids)',
                ['key' => $this->singleOfKey($inverse), 'ids' => $this->ids($this->targetMeta, $values)],
            );

            return;
        }
        [$table, $ownerColumn, $targetColumn, $extra] = $this->junction();
        $columns = [$ownerColumn, $targetColumn, ...array_keys($extra)];
        $sql = 'INSERT INTO ' . $q($table) . ' (' . implode(', ', array_map($q, $columns)) . ') VALUES (:owner, :target' . ($extra !== [] ? ', :type' : '') . ')';
        foreach ($this->ofIds() as $owner) {
            foreach ($this->ids($this->targetMeta, $values) as $target) {
                $conn->execute($sql, ['owner' => $owner, 'target' => $target] + ($extra !== [] ? ['type' => reset($extra)] : []));
            }
        }
    }

    /** One-to-many: clear the children's foreign key; many-to-many: delete the join-table rows. */
    public function remove(mixed $values): void
    {
        $this->assertToMany('Remove', 'set');
        $values = $this->list($values);
        if ($values === []) {
            return;
        }
        $conn = $this->dataSource->getConnection();
        $q = $conn->quote(...);
        if ($this->relation->type === RelationType::OneToMany) {
            $inverse = $this->inverse();
            $fk = $q((string) $inverse->joinColumn);
            $conn->execute(
                'UPDATE ' . $q($this->targetMeta->table) . " SET $fk = NULL WHERE $fk = :key AND " . $q($this->targetMeta->primary->name) . ' IN (:ids)',
                ['key' => $this->singleOfKey($inverse), 'ids' => $this->ids($this->targetMeta, $values)],
            );

            return;
        }
        [$table, $ownerColumn, $targetColumn, $extra] = $this->junction();
        $where = $q($ownerColumn) . ' IN (:owners) AND ' . $q($targetColumn) . ' IN (:targets)';
        $params = ['owners' => $this->ofIds(), 'targets' => $this->ids($this->targetMeta, $values)];
        foreach ($extra as $column => $value) {
            $where .= ' AND ' . $q($column) . ' = :type';
            $params['type'] = $value;
        }
        $conn->execute('DELETE FROM ' . $q($table) . ' WHERE ' . $where, $params);
    }

    public function addAndRemove(mixed $added, mixed $removed): void
    {
        $this->remove($removed);
        $this->add($added);
    }

    /** The related entity of a to-one relation (null when none). */
    public function loadOne(): ?object
    {
        return $this->loadMany()[0] ?? null;
    }

    /** @return list<object> the related entities of every `of` entity */
    public function loadMany(): array
    {
        $this->assertOf();
        $relation = $this->relation;
        if ($relation->isMorph() || $relation->isThrough()) {
            return $this->loadThroughRelationLoader();
        }
        $qb = new QueryBuilder($this->dataSource, $this->targetMeta->class, 'related');
        $refColumn = $this->targetMeta->columnName($relation->referencedColumn);
        if ($relation->holdsForeignKey()) {
            $qb->innerJoin($this->meta->table, 'nesto_of', 'nesto_of.' . $relation->joinColumn . ' = related.' . $refColumn)
                ->andWhere(['nesto_of.' . $this->meta->primary->name => ['In', $this->ofIds()]]);
        } elseif ($relation->type->usesPivot()) {
            [$table, $ownerColumn, $targetColumn, $extra] = $this->junction();
            $qb->innerJoin($table, 'nesto_jt', 'nesto_jt.' . $targetColumn . ' = related.' . $this->targetMeta->primary->name)
                ->andWhere(['nesto_jt.' . $ownerColumn => ['In', $this->ofIds()]]);
            foreach ($extra as $column => $value) {
                $qb->andWhere(['nesto_jt.' . $column => $value]);
            }
        } else {
            $inverse = $this->inverse();
            $keys = array_map(fn (mixed $of) => $this->ofKey($of, $inverse), $this->of);
            $qb->andWhere(['related.' . $inverse->joinColumn => ['In', array_values(array_filter($keys, fn ($k) => $k !== null))]]);
        }

        return $qb->orderBy('related.' . $this->targetMeta->primary->name)->getMany();
    }

    // ---- helpers --------------------------------------------------------------------------------

    /** Morph / through relations: load the `of` entities, then the relation with the RelationLoader. */
    private function loadThroughRelationLoader(): array
    {
        $entities = [];
        $ids = [];
        foreach ($this->of as $of) {
            if (is_object($of)) {
                $entities[] = $of;
            } else {
                $ids[] = $this->idOf($this->meta, $of);
            }
        }
        if ($ids !== []) {
            array_push($entities, ...(new QueryBuilder($this->dataSource, $this->meta->class, 'e'))->where([(string) $this->meta->primary->property => ['In', $ids]])->getMany());
        }
        (new RelationLoader($this->dataSource))->load($this->meta, $entities, [$this->relation->property]);
        $out = [];
        foreach ($entities as $entity) {
            $value = $entity->{$this->relation->property} ?? null;
            foreach (is_array($value) ? $value : ($value === null ? [] : [$value]) as $related) {
                if (! in_array($related, $out, true)) {
                    $out[] = $related;
                }
            }
        }

        return $out;
    }

    private function assertToMany(string $operation, string $instead): void
    {
        if ($this->relation->type !== RelationType::OneToMany && ! $this->relation->type->usesPivot()) {
            throw new OrmException("$operation operation is only supported for many-to-many and one-to-many relations. However given \"{$this->relation->property}\" has {$this->relation->type->value} relation. Use .$instead() method instead.");
        }
        $this->assertWritable();
    }

    private function assertWritable(): void
    {
        $this->assertOf();
        if ($this->relation->isThrough() || in_array($this->relation->type, [RelationType::MorphOne, RelationType::MorphMany], true)) {
            throw new OrmException("relation() cannot change {$this->relation->type->value} {$this->meta->class}::\${$this->relation->property}: save the entities instead.");
        }
    }

    private function assertOf(): void
    {
        if (! $this->ofSet || $this->of === []) {
            throw new OrmException('Call of($entityOrId) before changing or loading the relation.');
        }
    }

    private function inverse(): RelationMetadata
    {
        $inverse = $this->relation->inverseSide !== null ? $this->targetMeta->relation($this->relation->inverseSide) : null;
        if ($inverse?->joinColumn === null) {
            throw new OrmException("Relation {$this->meta->class}::\${$this->relation->property} needs an inverseSide pointing at a ManyToOne/OneToOne with a join column on {$this->targetMeta->class}.");
        }

        return $inverse;
    }

    /** @return array{0: string, 1: string, 2: string, 3: array<string, string>} join table, owner column, target column, morph type */
    private function junction(): array
    {
        [$table, $ownerColumn, $targetColumn] = QueryBuilder::joinTableFor($this->relation, $this->meta, $this->targetMeta);
        $extra = $this->relation->type->isMorph() ? [(string) $this->relation->morphTypeColumn => MorphMap::aliasFor((string) $this->relation->morphClass)] : [];

        return [$table, $ownerColumn, $targetColumn, $extra];
    }

    /** @return list<mixed> database primary keys of the `of` entities */
    private function ofIds(): array
    {
        return $this->ids($this->meta, $this->of);
    }

    /** The value children store to point at the single `of` entity. */
    private function singleOfKey(RelationMetadata $inverse): mixed
    {
        if (count($this->of) !== 1) {
            throw new OrmException('You cannot update relations of multiple entities with the same related object. Provide a single entity into "of" method.');
        }

        return $this->ofKey($this->of[0], $inverse) ?? throw new OrmException("The entity given to of() has no value for the key \"{$inverse->referencedColumn}\".");
    }

    private function ofKey(mixed $of, RelationMetadata $inverse): mixed
    {
        $column = $this->meta->column($inverse->referencedColumn) ?? $this->meta->primary;
        if (is_object($of)) {
            return $this->dataSource->hydrator()->columnValue($this->meta, $of, $column->name);
        }
        $id = $this->idOf($this->meta, $of);
        if ($column->primary) {
            return $id;
        }
        $conn = $this->dataSource->getConnection();

        return $conn->fetchColumn('SELECT ' . $conn->quote($column->name) . ' FROM ' . $conn->quote($this->meta->table) . ' WHERE ' . $conn->quote($this->meta->primary->name) . ' = :id', ['id' => $id]);
    }

    /** Value the owning side stores for $value (the target's referenced column, usually its primary key). */
    private function targetKey(mixed $value): mixed
    {
        $column = $this->targetMeta->column($this->relation->referencedColumn) ?? $this->targetMeta->primary;
        if (is_object($value)) {
            return $this->dataSource->hydrator()->columnValue($this->targetMeta, $value, $column->name);
        }
        if (is_array($value)) {
            $value = $value[(string) $column->property] ?? $value[$column->name] ?? null;
        }

        return TypeConverter::toDatabase($column, $value);
    }

    /** @param list<mixed> $values @return list<mixed> */
    private function ids(EntityMetadata $meta, array $values): array
    {
        return array_map(fn (mixed $v) => $this->idOf($meta, $v), $values);
    }

    private function idOf(EntityMetadata $meta, mixed $value): mixed
    {
        if (is_object($value)) {
            $value = $meta->primaryValue($value) ?? throw new OrmException($value::class . ' given to relation() has no primary key: save it first.');
        } elseif (is_array($value)) {
            $value = $value[(string) $meta->primary->property] ?? $value[$meta->primary->name] ?? throw new OrmException('Expected [\'' . $meta->primary->property . '\' => ...] in relation().');
        }

        return TypeConverter::toDatabase($meta->primary, $value);
    }

    /** @return list<mixed> */
    private function list(mixed $values): array
    {
        return is_array($values) && array_is_list($values) ? $values : ($values === null ? [] : [$values]);
    }
}
