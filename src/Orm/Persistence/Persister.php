<?php

declare(strict_types=1);

namespace Nesto\Orm\Persistence;

use DateTimeImmutable;
use Nesto\Orm\DataSource;
use Nesto\Orm\EntityMetadata;
use Nesto\Orm\Hydrator;
use Nesto\Orm\LazyCollection;
use Nesto\Orm\Mapping\ColumnMetadata;
use Nesto\Orm\Mapping\PropertyAccess;
use Nesto\Orm\Mapping\RelationMetadata;
use Nesto\Orm\Mapping\RelationType;
use Nesto\Orm\Mapping\TypeConverter;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\MorphMap;
use Nesto\Orm\OrmException;
use Nesto\Orm\QueryBuilder;
use Nesto\Orm\Repository;
use Nesto\Orm\SqlExpression;

/**
 * Writes entity graphs: Repository::save() / remove() / softRemove() / recover() with TypeORM's cascades.
 *
 *   save()        inserts / updates the entity (changed columns only), then per relation:
 *                 - owning to-one (ManyToOne, OneToOne with #[JoinColumn]): the target is saved FIRST when the
 *                   relation cascades "insert" (new target) / "update" (existing target); a new target without
 *                   cascade "insert" is an error (TypeORM would silently write a NULL foreign key)
 *                 - OneToMany / inverse OneToOne: children get this entity's key; new ones are inserted with
 *                   cascade "insert", changed ones updated with cascade "update", otherwise only their foreign
 *                   key is written. Children removed from the collection get the ManyToOne's orphanedRowAction
 *                   (nullify by default, delete, soft-delete, disable)
 *                 - ManyToMany (either side): join-table rows are diffed (insert added / delete removed);
 *                   targets are cascaded like owning to-one targets
 *                 - polymorphic relations keep their Laravel-style behaviour (targets / children always saved)
 *   remove()      cascade "remove": children are removed BEFORE the entity, targets AFTER it; the entity's
 *                 ManyToMany join-table rows are deleted with it
 *   softRemove()  / recover(): stamp / clear the DeleteDateColumn, cascading "soft-remove" / "recover"
 *
 * A relation property that is uninitialized, still holds its class default since the entity was loaded
 * (`public ?Profile $profile = null;`), or is an unloaded LazyCollection / lazy ghost is "not loaded"
 * (TypeORM's undefined) and is left alone.
 */
final class Persister
{
    private ?TreeMaintainer $trees = null;
    /** @var array<string, array{0: mixed}|null> "Class::property" => [class default] (null: none) — class metadata, safe across requests */
    private static array $classDefaults = [];

    public function __construct(private readonly DataSource $dataSource) {}

    /** Bookkeeping of #[Tree] entities (closure table, nested set, materialized path, levels). */
    public function trees(): TreeMaintainer
    {
        return $this->trees ??= new TreeMaintainer($this->dataSource);
    }

    /** TypeORM's SaveOptions / RemoveOptions keys (see run()). */
    public const SAVE_OPTIONS = ['data', 'listeners', 'transaction', 'chunk', 'reload'];
    public const REMOVE_OPTIONS = ['data', 'listeners', 'transaction', 'chunk'];

    /**
     * save() / remove() / softRemove() / recover() of the Repository and EntityManager with TypeORM's options:
     *
     *   listeners    false: no entity listeners / subscribers
     *   transaction  false: no transaction of its own (one is opened only when none is active anyway)
     *   data         handed to subscribers as $event->queryRunner->data while the operation runs
     *   chunk        write the entities in groups of this size, one group after the other (same transaction)
     *   reload       (save) false: do not read back what the database generated (defaults of generated columns,
     *                the bumped version, recomputed generated columns) — PostgreSQL still gets them for free
     *                through RETURNING, like in TypeORM; the primary key is always assigned
     *
     * Unknown keys and invalid values are errors.
     *
     * @param 'save'|'remove'|'softRemove'|'recover' $operation
     * @param list<object> $entities
     */
    public function run(string $operation, array $entities, array $options): void
    {
        $known = $operation === 'save' ? self::SAVE_OPTIONS : self::REMOVE_OPTIONS;
        $unknown = array_diff(array_keys($options), $known);
        if ($unknown !== []) {
            throw new OrmException("Unknown $operation() option \"" . implode('", "', $unknown) . '" (expected ' . implode(', ', $known) . ').');
        }
        foreach (['listeners', 'transaction', 'reload'] as $flag) {
            if (array_key_exists($flag, $options) && ! is_bool($options[$flag])) {
                throw new OrmException("$operation() option \"$flag\" must be a boolean, got " . get_debug_type($options[$flag]) . '.');
            }
        }
        $chunk = $options['chunk'] ?? null;
        if ($chunk !== null && (! is_int($chunk) || $chunk < 1)) {
            throw new OrmException("$operation() option \"chunk\" must be a positive integer, got " . var_export($chunk, true) . '.');
        }
        $listeners = $options['listeners'] ?? true;
        $chunks = $chunk === null ? [$entities] : array_chunk($entities, $chunk);
        $this->dataSource->runInTransaction(function () use ($operation, $chunks, $listeners, $options): void {
            $runner = array_key_exists('data', $options) ? $this->dataSource->currentQueryRunner() : null;
            $previous = $runner?->data;
            if ($runner !== null) {
                $runner->data = (array) $options['data'];
            }
            try {
                foreach ($chunks as $list) {
                    match ($operation) {
                        'save' => $this->save($list, $listeners, $options['reload'] ?? true),
                        'remove' => $this->remove($list, $listeners),
                        'softRemove' => $this->softRemove($list, false, $listeners),
                        'recover' => $this->softRemove($list, true, $listeners),
                    };
                }
            } finally {
                if ($runner !== null) {
                    $runner->data = $previous;
                }
            }
        }, $options['transaction'] ?? true);
    }

    // ---- save -----------------------------------------------------------------------------------

    /** @param list<object> $entities */
    public function save(array $entities, bool $listeners = true, bool $reload = true): void
    {
        $ctx = new PersistContext($listeners, $reload);
        foreach ($entities as $entity) {
            $this->persist($entity, $ctx);
        }
        $ctx->runDeferred();
        $ctx->runAfterEvents();
    }

    private function persist(object $entity, PersistContext $ctx, bool $mayInsert = true): void
    {
        if ($ctx->visited($entity)) {
            return;
        }
        $ctx->visit($entity);
        $meta = MetadataRegistry::get($entity::class);
        $meta->assertWritable('save()');
        $this->dataSource->getRepository($entity::class); // registers the entity (autoLoadEntities)

        // targets of owning to-one relations (and MorphTo) are written first: this row stores their key
        foreach ($meta->relations as $relation) {
            if (! $relation->holdsForeignKey() && $relation->type !== RelationType::MorphTo) {
                continue;
            }
            [$defined, $target] = $this->value($meta, $entity, $relation);
            if (! $defined || ! is_object($target) || self::isUninitializedLazy($target)) {
                continue;
            }
            $targetMeta = MetadataRegistry::get($target::class);
            $targetIsNew = $targetMeta->primaryValue($target) === null;
            if ($ctx->visited($target)) {
                if ($targetIsNew) {
                    // the target is being saved up the stack and waits for this row: write the key once it has one
                    $ctx->defer(fn () => $this->writeRow($meta, $entity, false));
                }
                continue;
            }
            if ($relation->type === RelationType::MorphTo) {
                if ($targetIsNew) {
                    $this->persist($target, $ctx);
                }
                continue;
            }
            $this->cascadeTarget($meta, $relation, $target, $targetIsNew, $ctx);
        }

        $pk = $meta->primaryValue($entity);
        $isNew = $pk === null || (! $this->dataSource->state()->isManaged($entity) && ! $this->loadStored($meta, $entity, $pk));
        if ($isNew && ! $mayInsert) {
            return; // reached through a cascade without "insert"
        }
        $this->writeRow($meta, $entity, $isNew, $ctx);

        foreach ($meta->relations as $relation) {
            if ($relation->isReadOnly() || $relation->holdsForeignKey() || $relation->type === RelationType::MorphTo) {
                continue;
            }
            [$defined, $value] = $this->value($meta, $entity, $relation);
            if (! $defined) {
                continue;
            }
            if ($relation->type->usesPivot()) {
                $this->syncJunction($meta, $entity, $relation, $value, $isNew, $ctx);
            } elseif ($relation->type === RelationType::MorphOne || $relation->type === RelationType::MorphMany) {
                $this->saveMorphChildren($meta, $entity, $relation, $value, $ctx);
            } else {
                $this->saveInverseSide($meta, $entity, $relation, $value, $isNew, $ctx);
            }
        }
    }

    /** Owning to-one / ManyToMany target: insert or update it per the relation's cascade options. */
    private function cascadeTarget(EntityMetadata $meta, RelationMetadata $relation, object $target, bool $targetIsNew, PersistContext $ctx): void
    {
        if ($targetIsNew) {
            if (! $relation->cascades('insert')) {
                throw self::unsaved($meta, $relation, $target);
            }
            $this->persist($target, $ctx);
        } elseif ($relation->cascades('update')) {
            $this->persist($target, $ctx, mayInsert: $relation->cascades('insert'));
        }
    }

    /**
     * INSERT a new row / UPDATE the changed columns of an existing one, keeping the snapshot in step.
     * With a context (not for the deferred foreign-key writes of a cycle) the entity's listeners / subscribers
     * fire: beforeInsert / beforeUpdate before the row is built (their changes are written), after-events once
     * the whole call is done. beforeUpdate only fires when columns changed (TypeORM).
     */
    private function writeRow(EntityMetadata $meta, object $entity, bool $isNew, ?PersistContext $ctx = null): void
    {
        $state = $this->dataSource->state();
        $hydrator = $this->dataSource->hydrator();
        $now = new DateTimeImmutable;
        $events = $ctx?->listeners ? $this->dataSource->broadcaster() : null;

        if ($isNew) {
            $events?->insert('beforeInsert', $meta, $entity);
            if ($meta->version !== null && (! $meta->isInitialized($entity, $meta->version) || $entity->{$meta->version} === null)) {
                $entity->{$meta->version} = 1;
            }
            GeneratedValues::prepareInsert($meta, $entity, $this->dataSource->getConnection()->driver());
            foreach ([$meta->createdAt, $meta->updatedAt] as $prop) {
                if ($prop !== null && (! $meta->isInitialized($entity, $prop) || $entity->{$prop} === null)) {
                    $entity->{$prop} = $now;
                }
            }
            $row = $hydrator->toRow($meta, $entity);
            $this->syncMorphProperties($meta, $entity, $row);
            if ($meta->tree !== null) {
                $row = $this->trees()->beforeInsert($meta, $entity, $row);
            }
            $row = GeneratedValues::insertRow($meta, $row);
            $returning = GeneratedValues::afterInsert($meta, $row);
            [$id, $values] = $this->insertReturning($meta, $row, $returning, $ctx?->reload ?? true);
            if ($meta->primary->generated && $id !== null && ! array_key_exists($meta->primary->name, $row)) {
                $meta->setPrimaryValue($entity, $id);
                $row[$meta->primary->name] = $id;
            }
            GeneratedValues::assign($meta, $entity, $returning, $values);
            if ($meta->tree !== null) {
                $values += $this->trees()->afterInsert($meta, $row + [$meta->primary->name => TypeConverter::toDatabase($meta->primary, $meta->primaryValue($entity))]);
            }
            $state->setSnapshot($entity, array_merge($row, $values));
            if ($events !== null) {
                $ctx->afterEvent(fn () => $events->insert('afterInsert', $meta, $entity));
            }

            return;
        }
        $snapshot = $state->snapshot($entity);
        [$row, $changes] = $this->changes($meta, $entity, $snapshot);
        if ($changes !== [] && $events !== null) {
            $events->update('beforeUpdate', $meta, $entity, $snapshot, self::changedColumns($changes));
            [$row, $changes] = $this->changes($meta, $entity, $snapshot); // the listeners' changes are written too
        }
        $move = null;
        if ($meta->tree !== null && $changes !== [] && ($move = $this->trees()->prepareMove($meta, $entity, $snapshot, $changes)) !== null && $meta->tree->levelProperty !== null) {
            [$row, $changes] = $this->changes($meta, $entity, $snapshot); // the node's new level
        }
        $computed = [];
        if ($changes !== []) {
            $changed = self::changedColumns($changes);
            if ($meta->updatedAt !== null) {
                $entity->{$meta->updatedAt} = $now;
                $changes[$meta->columnName($meta->updatedAt)] = TypeConverter::toDatabase($meta->column($meta->updatedAt), $now);
            }
            $this->updateByPk($meta, $meta->primaryValue($entity), $changes);
            if ($move !== null) {
                $changes += $this->trees()->move($meta, $move);
            }
            if ($ctx?->reload ?? true) {
                $changes = $this->readVersion($meta, $entity, $changes);
                $computed = GeneratedValues::reload($this->dataSource->getConnection(), $meta, $entity, GeneratedValues::computed($meta), $meta->primaryValue($entity));
            } else {
                $changes = $this->bumpVersion($meta, $entity, $snapshot, $changes);
            }
            if ($events !== null) {
                $ctx->afterEvent(fn () => $events->update('afterUpdate', $meta, $entity, $snapshot, $changed));
            }
        }
        $state->setSnapshot($entity, array_merge($snapshot ?? [], $row, $changes, $computed));
    }

    /**
     * The entity's row and the writable columns that differ from the snapshot; a #[VersionColumn] the caller did
     * not change itself is bumped in SQL (`version = version + 1`) whenever something else changed.
     *
     * @param array<string, mixed>|null $snapshot
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function changes(EntityMetadata $meta, object $entity, ?array $snapshot): array
    {
        $row = $this->dataSource->hydrator()->toRow($meta, $entity);
        $this->syncMorphProperties($meta, $entity, $row);
        $changes = [];
        foreach ($row as $column => $value) {
            if ($column === $meta->primary->name) {
                continue;
            }
            $type = isset($meta->columns[$column]) && $meta->columns[$column]->array ? 'array' : ($meta->columns[$column]->type ?? null);
            if ($snapshot === null || ! array_key_exists($column, $snapshot) || ! self::same($snapshot[$column], $value, $type)) {
                $changes[$column] = $value;
            }
        }
        $writable = GeneratedValues::updateRow($meta, $changes);
        $row = array_diff_key($row, array_diff_key($changes, $writable)); // values that are never written stay as loaded
        $changes = $writable;
        $version = $meta->versionColumn();
        if ($version !== null && $changes !== [] && ! array_key_exists($version->name, $changes)) {
            $changes[$version->name] = new SqlExpression($this->dataSource->getConnection()->quote($version->name) . ' + 1');
        }

        return [$row, $changes];
    }

    /** Changed column names as events report them (the automatic version bump excluded). @return list<string> */
    private static function changedColumns(array $changes): array
    {
        return array_values(array_filter(array_keys($changes), fn (string $c) => ! $changes[$c] instanceof SqlExpression));
    }

    /**
     * After an UPDATE that bumped the version in SQL: read the stored version back onto the entity (another
     * writer may have bumped it too) and into the changes recorded in the snapshot.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function readVersion(EntityMetadata $meta, object $entity, array $changes): array
    {
        $version = $meta->versionColumn();
        if ($version === null || ! ($changes[$version->name] ?? null) instanceof SqlExpression) {
            return $changes;
        }
        $conn = $this->dataSource->getConnection();
        $value = $conn->fetchColumn(
            'SELECT ' . $conn->quote($version->name) . ' FROM ' . $conn->quote($meta->table) . ' WHERE ' . $conn->quote($meta->primary->name) . ' = :pk',
            ['pk' => TypeConverter::toDatabase($meta->primary, $meta->primaryValue($entity))],
        );
        $changes[$version->name] = $value;
        $entity->{$meta->version} = TypeConverter::toPhp($version, $value);

        return $changes;
    }

    /**
     * reload: false — the version the UPDATE bumped in SQL, counted on the entity instead of read back (TypeORM
     * increments it in memory too).
     *
     * @param array<string, mixed>|null $snapshot
     * @param array<string, mixed>      $changes
     * @return array<string, mixed>
     */
    private function bumpVersion(EntityMetadata $meta, object $entity, ?array $snapshot, array $changes): array
    {
        $version = $meta->versionColumn();
        if ($version === null || ! ($changes[$version->name] ?? null) instanceof SqlExpression) {
            return $changes;
        }
        $next = (int) ($snapshot[$version->name] ?? 0) + 1;
        $changes[$version->name] = $next;
        $entity->{$meta->version} = TypeConverter::toPhp($version, $next);

        return $changes;
    }

    /**
     * OneToMany / inverse OneToOne: point the children at this entity, cascade them, then apply the
     * orphanedRowAction to rows that pointed here before but are no longer in the collection.
     */
    private function saveInverseSide(EntityMetadata $meta, object $entity, RelationMetadata $relation, mixed $value, bool $isNew, PersistContext $ctx): void
    {
        $state = $this->dataSource->state();
        $hydrator = $this->dataSource->hydrator();
        $targetMeta = MetadataRegistry::get($relation->target);
        $inverse = $relation->inverseSide !== null ? $targetMeta->relation($relation->inverseSide) : null;
        if ($inverse?->joinColumn === null) {
            throw new OrmException("Relation {$meta->class}::\${$relation->property} needs an inverseSide pointing at a ManyToOne/OneToOne with a join column on {$targetMeta->class}.");
        }
        $children = $relation->isToMany() ? (is_iterable($value) ? self::listOf($value) : []) : (is_object($value) ? [$value] : []);
        $key = $hydrator->columnValue($meta, $entity, $meta->columnName($inverse->referencedColumn));
        $previous = $isNew ? [] : $state->relationIds($entity, $relation->property);
        $kept = [];
        $pending = [];
        foreach ($children as $child) {
            if (! $ctx->visited($child)) {
                // (a child already visited is saved up the stack and reaches this entity through its own relation)
                $this->linkInverseChild($meta, $entity, $inverse, $targetMeta, $child);
                $pending[] = $child;
            }
            if ($targetMeta->primaryValue($child) !== null && ($ctx->visited($child) || self::sameKey($hydrator->foreignKeyValue($targetMeta, $child, $inverse), $key))) {
                $kept[] = self::pkOf($targetMeta, $child);
            }
        }
        // orphans first: a replaced inverse OneToOne must release its unique foreign key before the new one takes it
        if (! $isNew && $key !== null) {
            $this->applyOrphanedRowAction($targetMeta, $inverse, $key, $kept, $previous);
        }
        foreach ($pending as $child) {
            if ($targetMeta->primaryValue($child) === null) {
                if (! $relation->cascades('insert')) {
                    throw self::unsaved($meta, $relation, $child);
                }
                $this->persist($child, $ctx);
                if (self::sameKey($hydrator->foreignKeyValue($targetMeta, $child, $inverse), $key)) {
                    $kept[] = self::pkOf($targetMeta, $child);
                }
            } elseif ($relation->cascades('update')) {
                $this->persist($child, $ctx);
            } else {
                $this->writeForeignKey($targetMeta, $child, $inverse);
            }
        }
        $state->setRelationIds($entity, $relation->property, $kept);
    }

    /** OneToMany / inverse OneToOne child: give it this entity's key unless it points at a parent itself. */
    private function linkInverseChild(EntityMetadata $meta, object $entity, RelationMetadata $inverse, EntityMetadata $targetMeta, object $child): void
    {
        if ($targetMeta->isInitialized($child, $inverse->property) && $child->{$inverse->property} === null) {
            // `public ?Team $team = null;` on a child means "not set", not "detach": use this parent
            unset($child->{$inverse->property});
        }
        if (! $targetMeta->isInitialized($child, $inverse->property)) {
            $key = $this->dataSource->hydrator()->columnValue($meta, $entity, $meta->columnName($inverse->referencedColumn));
            $this->dataSource->state()->setPendingForeignKey($child, (string) $inverse->joinColumn, $key);
        }
    }

    /** Without cascade "update" only the child's foreign key is written (when it changed). */
    private function writeForeignKey(EntityMetadata $targetMeta, object $child, RelationMetadata $inverse): void
    {
        $state = $this->dataSource->state();
        $column = (string) $inverse->joinColumn;
        $fk = $this->dataSource->hydrator()->foreignKeyValue($targetMeta, $child, $inverse);
        $snapshot = $state->snapshot($child);
        if ($snapshot !== null && array_key_exists($column, $snapshot) && self::same($snapshot[$column], $fk)) {
            return;
        }
        $this->updateByPk($targetMeta, $targetMeta->primaryValue($child), [$column => $fk]);
        $state->setSnapshot($child, array_merge($snapshot ?? [], [$column => $fk]));
    }

    /**
     * Rows of $targetMeta pointing at $key that are not in $kept: nullify / delete / soft-delete them.
     * With the ids known from loading only those are touched; unknown → every other row pointing here (TypeORM).
     *
     * @param list<mixed>      $kept
     * @param list<mixed>|null $previous
     */
    private function applyOrphanedRowAction(EntityMetadata $targetMeta, RelationMetadata $inverse, mixed $key, array $kept, ?array $previous): void
    {
        $action = $inverse->orphanedRowAction ?? 'nullify';
        if ($action === 'disable') {
            return;
        }
        $conn = $this->dataSource->getConnection();
        $q = $conn->quote(...);
        $pk = $q($targetMeta->primary->name);
        $where = $q((string) $inverse->joinColumn) . ' = :nesto_key';
        $params = ['nesto_key' => $key];
        $where .= \Nesto\Orm\RelationLoader::discriminatorSql($conn, $targetMeta, null, $params);
        if ($previous !== null) {
            $keptKeys = array_map('strval', $kept);
            $removed = array_values(array_filter($previous, fn ($id) => ! in_array((string) $id, $keptKeys, true)));
            if ($removed === []) {
                return;
            }
            $where .= " AND $pk IN (:nesto_ids)";
            $params['nesto_ids'] = $removed;
        } elseif ($kept !== []) {
            $where .= " AND $pk NOT IN (:nesto_ids)";
            $params['nesto_ids'] = $kept;
        }
        $table = $q($targetMeta->table);
        match ($action) {
            'delete' => $conn->execute("DELETE FROM $table WHERE $where", $params),
            'soft-delete' => $conn->execute(
                "UPDATE $table SET " . $q(($targetMeta->deletedAtColumn() ?? throw new OrmException("orphanedRowAction 'soft-delete' on {$targetMeta->class}::\${$inverse->property} needs a #[DeleteDateColumn] on {$targetMeta->class}."))->name)
                    . " = :nesto_now WHERE $where AND " . $q($targetMeta->deletedAtColumn()->name) . ' IS NULL',
                $params + ['nesto_now' => TypeConverter::toDatabase($targetMeta->deletedAtColumn(), new DateTimeImmutable)],
            ),
            default => $conn->execute("UPDATE $table SET " . $q((string) $inverse->joinColumn) . " = NULL WHERE $where", $params),
        };
    }

    /** MorphOne / MorphMany children are always saved (Laravel-style), with this entity's type alias and key. */
    private function saveMorphChildren(EntityMetadata $meta, object $entity, RelationMetadata $relation, mixed $value, PersistContext $ctx): void
    {
        $targetMeta = MetadataRegistry::get($relation->target);
        $children = $relation->isToMany() ? (is_iterable($value) ? self::listOf($value) : []) : (is_object($value) ? [$value] : []);
        foreach ($children as $child) {
            if ($ctx->visited($child)) {
                continue;
            }
            $this->linkMorphChild($meta, $entity, $relation, $targetMeta, $child);
            $this->persist($child, $ctx);
        }
    }

    /** MorphOne / MorphMany child: write this entity's type alias and key into the child's morph columns. */
    private function linkMorphChild(EntityMetadata $meta, object $entity, RelationMetadata $relation, EntityMetadata $targetMeta, object $child): void
    {
        $hydrator = $this->dataSource->hydrator();
        $state = $this->dataSource->state();
        $alias = MorphMap::aliasFor((string) $relation->morphClass);
        $key = $hydrator->columnValue($meta, $entity, $meta->columnName($relation->localKey ?? (string) $meta->primary->property));
        $morphTo = null;
        foreach ($targetMeta->relations as $candidate) {
            if ($candidate->type === RelationType::MorphTo && $candidate->morphTypeColumn === $relation->morphTypeColumn && $candidate->morphIdColumn === $relation->morphIdColumn) {
                $morphTo = $candidate;
                break;
            }
        }
        if ($morphTo !== null && $targetMeta->isInitialized($child, $morphTo->property)) {
            if ($child->{$morphTo->property} !== null) {
                return; // explicitly assigned
            }
            unset($child->{$morphTo->property});
        }
        foreach ([(string) $relation->morphTypeColumn => $alias, (string) $relation->morphIdColumn => $key] as $columnName => $value) {
            $column = $targetMeta->columns[$columnName] ?? null;
            if ($column === null) {
                throw new OrmException("{$targetMeta->class} has no column \"$columnName\" for {$meta->class}::\${$relation->property}: add #[MorphTo] (or the column) to it.");
            }
            $value = $columnName === $relation->morphIdColumn ? Hydrator::morphKey($column, $value) : $value;
            if ($column->property !== null && $morphTo === null) {
                $child->{$column->property} = TypeConverter::toPhp($column, $value);
            } else {
                $state->setPendingForeignKey($child, $columnName, $value);
            }
        }
    }

    /** Keep MorphTo type / id columns declared as properties in step with the entity assigned to the relation. */
    private function syncMorphProperties(EntityMetadata $meta, object $entity, array $row): void
    {
        foreach ($meta->columns as $column) {
            if ($column->morph !== null && $column->property !== null && array_key_exists($column->name, $row)) {
                $relation = $meta->relation((string) $column->relation);
                if ($meta->isInitialized($entity, $relation->property) && is_object($entity->{$relation->property})) {
                    $entity->{$column->property} = TypeConverter::toPhp($column, $row[$column->name]);
                }
            }
        }
    }

    /**
     * Join-table rows of a ManyToMany (either side), MorphToMany or MorphedByMany: insert the added pairs and
     * delete the removed ones. The previous ids come from loading / the last save, else from the join table.
     * New targets need cascade "insert" (polymorphic pivots always insert them).
     */
    private function syncJunction(EntityMetadata $meta, object $entity, RelationMetadata $relation, mixed $value, bool $isNew, PersistContext $ctx): void
    {
        if (! is_iterable($value)) {
            return;
        }
        $state = $this->dataSource->state();
        $targetMeta = MetadataRegistry::get($relation->target);
        $morph = $relation->type->isMorph();
        $ids = [];
        foreach ($value as $target) {
            if (! is_object($target)) {
                continue;
            }
            $targetIsNew = $targetMeta->primaryValue($target) === null;
            if ($ctx->visited($target)) {
                if ($targetIsNew) {
                    // waits for this entity up the stack: write the rows once every entity has an id
                    $ctx->defer(fn () => $this->syncJunction($meta, $entity, $relation, $value, false, $ctx));

                    return;
                }
            } elseif ($morph) {
                if ($targetIsNew) {
                    $this->persist($target, $ctx);
                }
            } elseif (! self::isUninitializedLazy($target)) {
                $this->cascadeTarget($meta, $relation, $target, $targetIsNew, $ctx);
            }
            $ids[(string) self::pkOf($targetMeta, $target)] = self::pkOf($targetMeta, $target);
        }
        $known = $isNew ? [] : $state->relationIds($entity, $relation->property);
        if ($known !== null && self::sameIds($known, array_values($ids))) {
            $state->setRelationIds($entity, $relation->property, array_values($ids));

            return;
        }
        $conn = $this->dataSource->getConnection();
        $q = $conn->quote(...);
        [$joinTable, $ownerColumn, $targetColumn] = QueryBuilder::joinTableFor($relation, $meta, $targetMeta);
        $owner = self::pkOf($meta, $entity);
        $where = $q($ownerColumn) . ' = :owner';
        $params = ['owner' => $owner];
        $columns = [$ownerColumn => $owner];
        if ($morph) {
            $where .= ' AND ' . $q((string) $relation->morphTypeColumn) . ' = :type';
            $params['type'] = $columns[(string) $relation->morphTypeColumn] = MorphMap::aliasFor((string) $relation->morphClass);
        }
        if ($known === null) {
            $known = array_column($conn->fetchAll('SELECT ' . $q($targetColumn) . ' FROM ' . $q($joinTable) . ' WHERE ' . $where, $params), $targetColumn);
        }
        $knownKeys = array_map('strval', $known);
        $removed = [];
        foreach ($known as $id) {
            if (! isset($ids[(string) $id]) && $ctx->claimJunctionRow(self::junctionKey($joinTable, $columns + [$targetColumn => $id]))) {
                $removed[] = $id;
            }
        }
        if ($removed !== []) {
            $conn->execute('DELETE FROM ' . $q($joinTable) . ' WHERE ' . $where . ' AND ' . $q($targetColumn) . ' IN (:removed)', $params + ['removed' => $removed]);
        }
        foreach ($ids as $stringId => $id) {
            $row = $columns + [$targetColumn => $id];
            if (in_array((string) $stringId, $knownKeys, true) || ! $ctx->claimJunctionRow(self::junctionKey($joinTable, $row))) {
                continue;
            }
            $placeholders = [];
            $values = [];
            foreach (array_values($row) as $i => $v) {
                $placeholders[] = ':j' . $i;
                $values['j' . $i] = $v;
            }
            $conn->execute('INSERT INTO ' . $q($joinTable) . ' (' . implode(', ', array_map($q, array_keys($row))) . ') VALUES (' . implode(', ', $placeholders) . ')', $values);
        }
        $state->setRelationIds($entity, $relation->property, array_values($ids));
    }

    // ---- remove / softRemove / recover ------------------------------------------------------------

    /** @param list<object> $entities */
    public function remove(array $entities, bool $listeners = true): void
    {
        $ctx = new PersistContext($listeners);
        foreach ($entities as $entity) {
            $this->removeEntity($entity, $ctx);
        }
        $ctx->runAfterEvents();
    }

    private function removeEntity(object $entity, PersistContext $ctx): void
    {
        if ($ctx->visited($entity) || self::isUninitializedLazy($entity)) {
            return;
        }
        $ctx->visit($entity);
        $meta = MetadataRegistry::get($entity::class);
        $meta->assertWritable('remove()');
        $pk = $meta->primaryValue($entity);
        if ($pk === null) {
            $this->dataSource->state()->forget($entity);

            return;
        }
        // rows pointing at this one go first
        foreach ($meta->relations as $relation) {
            if ($relation->cascades('remove') && ($relation->type === RelationType::OneToMany || ($relation->type === RelationType::OneToOne && ! $relation->holdsForeignKey()))) {
                foreach ($this->related($meta, $entity, $relation) as $child) {
                    $this->removeEntity($child, $ctx);
                }
            }
        }
        $events = $ctx->listeners ? $this->dataSource->broadcaster() : null;
        $snapshot = $this->dataSource->state()->snapshot($entity);
        $events?->remove('beforeRemove', $meta, $entity, $pk, $snapshot);
        $conn = $this->dataSource->getConnection();
        $q = $conn->quote(...);
        $id = TypeConverter::toDatabase($meta->primary, $pk);
        if ($events !== null) {
            $ctx->afterEvent(fn () => $events->remove('afterRemove', $meta, $entity, $pk, $snapshot));
        }
        foreach ($meta->relations as $relation) {
            if ($relation->type === RelationType::ManyToMany) {
                [$joinTable, $ownerColumn] = QueryBuilder::joinTableFor($relation, $meta, MetadataRegistry::get($relation->target));
                $conn->execute('DELETE FROM ' . $q($joinTable) . ' WHERE ' . $q($ownerColumn) . ' = :id', ['id' => $id]);
            }
        }
        $bounds = $meta->tree !== null ? $this->trees()->beforeRemove($meta, $id) : null;
        $conn->execute('DELETE FROM ' . $q($meta->table) . ' WHERE ' . $q($meta->primary->name) . ' = :id', ['id' => $id]);
        if ($meta->tree !== null) {
            $this->trees()->afterRemove($meta, $bounds);
        }
        $this->dataSource->state()->forget($entity);
        // then the entities this one pointed at
        foreach ($meta->relations as $relation) {
            if ($relation->cascades('remove') && ($relation->holdsForeignKey() || $relation->type === RelationType::ManyToMany)) {
                foreach ($this->related($meta, $entity, $relation) as $target) {
                    $this->removeEntity($target, $ctx);
                }
            }
        }
    }

    /** @param list<object> $entities */
    public function softRemove(array $entities, bool $recover = false, bool $listeners = true): void
    {
        $ctx = new PersistContext($listeners);
        foreach ($entities as $entity) {
            $this->softRemoveEntity($entity, $recover, $ctx);
        }
        $ctx->runAfterEvents();
    }

    private function softRemoveEntity(object $entity, bool $recover, PersistContext $ctx): void
    {
        if ($ctx->visited($entity) || self::isUninitializedLazy($entity)) {
            return;
        }
        $ctx->visit($entity);
        $meta = MetadataRegistry::get($entity::class);
        $meta->assertWritable($recover ? 'recover()' : 'softRemove()');
        $operation = $recover ? 'recover' : 'soft-remove';
        $column = $meta->deletedAtColumn() ?? throw new OrmException("{$meta->class} has no #[DeleteDateColumn]: " . ($recover ? 'recover()' : 'softRemove()') . ' needs one.');
        $pk = $meta->primaryValue($entity) ?? throw new OrmException("Cannot $operation a {$meta->class} that was never saved (it has no primary key).");
        $state = $this->dataSource->state();
        $events = $ctx->listeners ? $this->dataSource->broadcaster() : null;
        $event = $recover ? 'Recover' : 'SoftRemove';
        $snapshot = $state->snapshot($entity);
        $events?->remove('before' . $event, $meta, $entity, $pk, $snapshot);
        $value = $recover ? null : new DateTimeImmutable;
        $entity->{$meta->deletedAt} = $value;
        $dbValue = TypeConverter::toDatabase($column, $value);
        // like TypeORM's soft-delete / restore queries: the update date and version move too
        $changes = [$column->name => $dbValue];
        if ($meta->updatedAt !== null && $meta->updatedAt !== $meta->deletedAt) {
            $entity->{$meta->updatedAt} = $now = new DateTimeImmutable;
            $changes[$meta->columnName($meta->updatedAt)] = TypeConverter::toDatabase($meta->column($meta->updatedAt), $now);
        }
        if (($version = $meta->versionColumn()) !== null) {
            $changes[$version->name] = new SqlExpression($this->dataSource->getConnection()->quote($version->name) . ' + 1');
        }
        $this->updateByPk($meta, $pk, $changes);
        $changes = $this->readVersion($meta, $entity, $changes);
        $state->setSnapshot($entity, array_merge($state->snapshot($entity) ?? [], $changes));
        if ($events !== null) {
            $ctx->afterEvent(fn () => $events->remove('after' . $event, $meta, $entity, $pk, $snapshot));
        }
        foreach ($meta->relations as $relation) {
            if ($relation->cascades($operation)) {
                foreach ($this->related($meta, $entity, $relation) as $related) {
                    $this->softRemoveEntity($related, $recover, $ctx);
                }
            }
        }
    }

    // ---- low-level writes (shared with Repository::insert()) ---------------------------------------

    /** @param array<string, mixed> $row column => db value. Returns the generated id when available. */
    public function insertRow(EntityMetadata $meta, array $row): int|string|null
    {
        return $this->insertReturning($meta, $row, [])[0];
    }

    /**
     * INSERT a row and read back what the database generated: the primary key when the row has none, and the
     * given columns (RETURNING on PostgreSQL, a SELECT by primary key elsewhere).
     *
     * @param array<string, mixed> $row column => db value
     * @param list<\Nesto\Orm\Mapping\ColumnMetadata> $returning
     * @return array{0: int|string|null, 1: array<string, mixed>} [generated id, column => db value]
     */
    public function insertReturning(EntityMetadata $meta, array $row, array $returning, bool $reload = true): array
    {
        $meta->assertWritable('insert()');
        $conn = $this->dataSource->getConnection();
        $table = $conn->quote($meta->table);
        $pkName = $meta->primary->name;
        $dbKey = $meta->primary->generated && ! array_key_exists($pkName, $row);
        if ($row === []) {
            $sql = $conn->driver() === 'mysql' ? "INSERT INTO $table () VALUES ()" : "INSERT INTO $table DEFAULT VALUES";
            $params = [];
        } else {
            $columns = array_map($conn->quote(...), array_keys($row));
            $params = [];
            $placeholders = [];
            $i = 0;
            foreach ($row as $value) {
                $name = 'v' . $i++;
                $params[$name] = $value;
                $placeholders[] = ':' . $name;
            }
            $sql = "INSERT INTO $table (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        }
        $names = array_map(fn ($c) => $c->name, $returning);
        if ($conn->driver() === 'pgsql' && ($dbKey || $names !== [])) {
            $returned = array_values(array_unique([...($dbKey ? [$pkName] : []), ...$names]));
            $result = $conn->fetchOne($sql . ' RETURNING ' . implode(', ', array_map($conn->quote(...), $returned)), $params) ?? [];
            $id = $dbKey ? ($result[$pkName] ?? null) : null;
            unset($result[$pkName]);

            return [$id === null ? null : (is_int($id) || preg_match('/^\d+$/', (string) $id) === 1 ? (int) $id : (string) $id), $result];
        }
        $conn->execute($sql, $params);
        $id = $dbKey && $meta->primary->generationStrategy !== 'uuid' ? $conn->lastInsertId() : null;
        $values = $reload ? GeneratedValues::fetch($conn, $meta, $returning, $id ?? ($row[$pkName] ?? null)) : [];

        return [$id, $values];
    }

    /** @param array<string, mixed> $changes column => db value */
    private function updateByPk(EntityMetadata $meta, mixed $pk, array $changes): void
    {
        $conn = $this->dataSource->getConnection();
        $sets = [];
        $params = ['nesto_pk' => TypeConverter::toDatabase($meta->primary, $pk)];
        $i = 0;
        foreach ($changes as $column => $value) {
            if ($value instanceof SqlExpression) {
                $sets[] = $conn->quote($column) . ' = ' . $value->sql;
                continue;
            }
            $name = 'v' . $i++;
            $sets[] = $conn->quote($column) . ' = :' . $name;
            $params[$name] = $value;
        }
        $conn->execute(
            'UPDATE ' . $conn->quote($meta->table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $conn->quote($meta->primary->name) . ' = :nesto_pk',
            $params,
        );
    }

    /**
     * An entity with a key that was never loaded (built with create() / `new`, or a reference stub of a cascade):
     * read its row once, like TypeORM's save() loads the database entity. No row: false (it is inserted). Else the
     * row becomes its snapshot, so only columns that differ are written, and what the entity does not set keeps
     * the stored value: an uninitialized column property, or one still holding its class default that was not
     * given to create() / merge(), takes the stored value (so listeners see a whole entity and it is not written);
     * a relation left at its class default is "not loaded" (an owning one keeps the stored foreign key, an inverse
     * / to-many one is ignored).
     */
    private function loadStored(EntityMetadata $meta, object $entity, mixed $pk): bool
    {
        $conn = $this->dataSource->getConnection();
        $row = $conn->fetchOne(
            'SELECT ' . implode(', ', array_map($conn->quote(...), array_keys($meta->columns))) . ' FROM ' . $conn->quote($meta->table)
                . ' WHERE ' . $conn->quote($meta->primary->name) . ' = :pk',
            ['pk' => TypeConverter::toDatabase($meta->primary, $pk)],
        );
        if ($row === null) {
            return false;
        }
        $state = $this->dataSource->state();
        $state->setSnapshot($entity, $row);
        foreach ($meta->columns as $column) {
            if ($column->property === null || $column->primary || (PropertyAccess::isInitialized($entity, $column)
                && ! $this->isUntouchedDefault($entity, (string) $column->property, PropertyAccess::get($entity, $column), $column))) {
                continue;
            }
            $value = TypeConverter::toPhp($column, $row[$column->name]);
            if ($value === null && ! PropertyAccess::allowsNull($entity, $column)) {
                PropertyAccess::unset($entity, $column);
            } else {
                PropertyAccess::set($entity, $column, $value);
            }
        }
        $defaults = [];
        foreach ($meta->relations as $relation) {
            if ($relation->isReadOnly() || ! $meta->isInitialized($entity, $relation->property)
                || ! $this->isUntouchedDefault($entity, $relation->property, $entity->{$relation->property})) {
                continue;
            }
            if ($relation->holdsForeignKey() || $relation->type === RelationType::MorphTo) {
                unset($entity->{$relation->property}); // "not loaded": the stored foreign key stays
            } else {
                $defaults[] = $relation->property;
            }
        }
        $state->markDefaultRelations($entity, $defaults);

        return true;
    }

    /** Does the property still hold its class default, without having been given to create() / merge()? */
    private function isUntouchedDefault(object $entity, string $property, mixed $value, ?ColumnMetadata $column = null): bool
    {
        if ($this->dataSource->state()->isGiven($entity, $property)) {
            return false;
        }
        $class = $column !== null && $column->embedded !== [] ? $column->embedded[array_key_last($column->embedded)][1] : $entity::class;
        $name = $column?->propertyName() ?? $property;
        $key = $class . '::' . $name;
        if (! array_key_exists($key, self::$classDefaults)) {
            $reflection = new \ReflectionProperty($class, $name);
            self::$classDefaults[$key] = $reflection->hasDefaultValue() ? [$reflection->getDefaultValue()] : null;
        }

        return self::$classDefaults[$key] !== null && self::$classDefaults[$key][0] === $value;
    }

    // ---- helpers --------------------------------------------------------------------------------

    /**
     * [defined, value] of a relation property. Not defined ("not loaded", TypeORM's undefined): uninitialized,
     * an unloaded LazyCollection, or still the class default it had when the entity was hydrated.
     *
     * @return array{0: bool, 1: mixed}
     */
    private function value(EntityMetadata $meta, object $entity, RelationMetadata $relation): array
    {
        if (! $meta->isInitialized($entity, $relation->property)) {
            return [false, null];
        }
        $value = $entity->{$relation->property};
        if ($value instanceof LazyCollection) {
            return $value->isLoaded() ? [true, $value->toArray()] : [false, null];
        }
        if (($value === null || $value === []) && $this->dataSource->state()->isDefaultRelation($entity, $relation->property)) {
            return [false, null];
        }
        if (! $relation->holdsForeignKey() && $relation->type !== RelationType::MorphTo && $value !== null) {
            // ids / [pk => id] maps (loadRelationIds, plain input) stand for existing rows: reference stubs
            $targetMeta = MetadataRegistry::get($relation->target);
            $stub = fn (mixed $item): mixed => is_object($item) ? $item : $this->dataSource->hydrator()->fromPlain(
                $targetMeta,
                is_array($item) ? $item : [(string) $targetMeta->primary->property => $item],
            );
            $value = $relation->isToMany() ? (is_iterable($value) ? array_map($stub, self::listOf($value)) : $value) : $stub($value);
        }

        return [true, $value];
    }

    /** @return list<object> the loaded related entities of a relation (none when it is not loaded) */
    private function related(EntityMetadata $meta, object $entity, RelationMetadata $relation): array
    {
        [$defined, $value] = $this->value($meta, $entity, $relation);
        if (! $defined) {
            return [];
        }
        if (is_object($value) && ! $value instanceof \Traversable) {
            return self::isUninitializedLazy($value) ? [] : [$value];
        }

        return is_iterable($value) ? array_values(array_filter(self::listOf($value), 'is_object')) : [];
    }

    private static function unsaved(EntityMetadata $meta, RelationMetadata $relation, object $related): OrmException
    {
        return new OrmException(
            "{$meta->class}::\${$relation->property} holds a new " . $related::class . ' that is not saved: save it first, or add'
            . " cascade: true (or cascade: ['insert']) to the relation.",
        );
    }

    public static function isUninitializedLazy(object $object): bool
    {
        return PHP_VERSION_ID >= 80400 && (new \ReflectionClass($object))->isUninitializedLazyObject($object);
    }

    /** @return list<mixed> */
    private static function listOf(iterable $value): array
    {
        return is_array($value) ? array_values($value) : iterator_to_array($value, false);
    }

    private static function pkOf(EntityMetadata $meta, object $entity): mixed
    {
        return TypeConverter::toDatabase($meta->primary, $meta->primaryValue($entity));
    }

    private static function sameKey(mixed $a, mixed $b): bool
    {
        return $a !== null && $b !== null && (string) $a === (string) $b;
    }

    /** @param list<mixed> $a @param list<mixed> $b */
    private static function sameIds(array $a, array $b): bool
    {
        $a = array_map('strval', $a);
        $b = array_map('strval', $b);
        sort($a);
        sort($b);

        return $a === $b;
    }

    /** @param array<string, mixed> $row */
    private static function junctionKey(string $table, array $row): string
    {
        ksort($row);
        $parts = [];
        foreach ($row as $column => $value) {
            $parts[] = $column . '=' . $value;
        }

        return $table . '|' . implode('|', $parts);
    }

    /**
     * Does the snapshot value (as the driver returned it) equal the value about to be written?
     * Normalizes driver representations: "9.50" (DECIMAL on MySQL/PostgreSQL) vs 9.5, 1 vs true,
     * and JSON re-formatted by the server (MySQL JSON / PostgreSQL JSONB) vs json_encode().
     */
    public static function same(mixed $a, mixed $b, ?string $type = null): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if ($type === 'array' && is_string($a) && is_string($b)) {
            return \Nesto\Orm\Mapping\PgArray::parse($a) === \Nesto\Orm\Mapping\PgArray::parse($b); // PostgreSQL array literals
        }
        if (in_array($type, ['json', 'jsonb', 'simple-json'], true) && is_string($a) && is_string($b)) {
            try {
                return json_decode($a, true, 512, JSON_THROW_ON_ERROR) === json_decode($b, true, 512, JSON_THROW_ON_ERROR)
                    || self::sameJson(json_decode($a, true, 512, JSON_THROW_ON_ERROR), json_decode($b, true, 512, JSON_THROW_ON_ERROR));
            } catch (\JsonException) {
                return $a === $b;
            }
        }
        if (! is_bool($a) && ! is_bool($b) && is_numeric($a) && is_numeric($b)) {
            $intLike = fn (mixed $v): bool => is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', $v) === 1);
            if ($intLike($a) && $intLike($b)) {
                return (string) $a === (string) $b;
            }

            return self::number((float) $a) === self::number((float) $b);
        }
        $norm = fn (mixed $v): string => is_bool($v) ? (string) (int) $v : (is_float($v) ? self::number($v) : (string) $v);

        return $norm($a) === $norm($b);
    }

    /** JSON objects compare regardless of key order (JSONB sorts keys). */
    private static function sameJson(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            if (array_is_list($a) !== array_is_list($b) || count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (! array_key_exists($key, $b) || ! self::sameJson($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b || (is_numeric($a) && is_numeric($b) && (float) $a === (float) $b);
    }

    private static function number(float $v): string
    {
        return rtrim(rtrim(number_format($v, 10, '.', ''), '0'), '.');
    }
}
