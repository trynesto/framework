<?php

declare(strict_types=1);

namespace Nesto\Orm\Events;

use Closure;
use Nesto\Orm\Connection;
use Nesto\Orm\DataSource;
use Nesto\Orm\EntityMetadata;
use Nesto\Orm\Hydrator;
use Nesto\Orm\LazyCollection;
use Nesto\Orm\Mapping\TypeConverter;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\Persistence\Persister;
use ReflectionClass;
use SplObjectStorage;

/**
 * Dispatches entity listeners (#[BeforeInsert] …) and subscribers (TypeORM's Broadcaster), one per DataSource.
 * Entity listeners run first, then the subscribers whose listenTo() matches, in registration order.
 *
 * AfterLoad is collected while a load runs (find*, getMany/getOne, lazy loads, possibly nested) and fired once
 * the outermost one finished, walking the result graph relations-first like TypeORM. The pending set is
 * per load and emptied when it ends or fails, so nothing survives a request (worker mode).
 */
final class Broadcaster
{
    private const REMOVE_EVENTS = [
        'beforeRemove' => RemoveEvent::class, 'afterRemove' => RemoveEvent::class,
        'beforeSoftRemove' => SoftRemoveEvent::class, 'afterSoftRemove' => SoftRemoveEvent::class,
        'beforeRecover' => RecoverEvent::class, 'afterRecover' => RecoverEvent::class,
    ];

    private const TRANSACTION_EVENTS = [
        'beforeTransactionStart' => TransactionStartEvent::class, 'afterTransactionStart' => TransactionStartEvent::class,
        'beforeTransactionCommit' => TransactionCommitEvent::class, 'afterTransactionCommit' => TransactionCommitEvent::class,
        'beforeTransactionRollback' => TransactionRollbackEvent::class, 'afterTransactionRollback' => TransactionRollbackEvent::class,
    ];

    private int $loadDepth = 0;
    /** @var SplObjectStorage<object, EntityMetadata> hydrated entities waiting for AfterLoad */
    private SplObjectStorage $loaded;
    private bool $inQueryEvent = false;

    public function __construct(private readonly DataSource $dataSource)
    {
        $this->loaded = new SplObjectStorage;
    }

    /** Hook transaction and query events into the connection. */
    public function install(Connection $connection): void
    {
        $connection->setTransactionListener($this->transactionPhase(...));
        $connection->setQueryListeners($this->beforeQuery(...), $this->afterQuery(...));
    }

    // ---- persistence events -------------------------------------------------------------------------

    /** beforeInsert / afterInsert. @param object|array<string, mixed> $entity */
    public function insert(string $event, EntityMetadata $meta, object|array $entity): void
    {
        $this->dispatch($event, $meta, $entity, fn (array $c) => new InsertEvent(...$c, entity: $entity, metadata: $meta));
    }

    /**
     * beforeUpdate / afterUpdate.
     *
     * @param object|array<string, mixed>|null $entity
     * @param array<string, mixed>|null        $snapshot the row as loaded / last saved (databaseEntity)
     * @param list<string>                     $changedColumns column names
     */
    public function update(string $event, EntityMetadata $meta, object|array|null $entity, ?array $snapshot = null, array $changedColumns = []): void
    {
        $this->dispatch($event, $meta, $entity, function (array $c) use ($meta, $entity, $snapshot, $changedColumns): UpdateEvent {
            $columns = [];
            $relations = [];
            foreach ($changedColumns as $name) {
                $column = $meta->columns[$name] ?? null;
                if ($column === null) {
                    continue;
                }
                $columns[] = $column;
                if ($column->relation !== null && ($relation = $meta->relation($column->relation)) !== null && ! in_array($relation, $relations, true)) {
                    $relations[] = $relation;
                }
            }

            return new UpdateEvent(...$c, entity: $entity, metadata: $meta, databaseEntity: $snapshot === null ? null : self::detached($meta, $snapshot), updatedColumns: $columns, updatedRelations: $relations);
        });
    }

    /** before/afterRemove, before/afterSoftRemove, before/afterRecover. @param array<string, mixed>|null $snapshot */
    public function remove(string $event, EntityMetadata $meta, ?object $entity, mixed $id = null, ?array $snapshot = null): void
    {
        $class = self::REMOVE_EVENTS[$event];
        $this->dispatch($event, $meta, $entity, fn (array $c) => new $class(...$c, entity: $entity, metadata: $meta, databaseEntity: $snapshot === null ? null : self::detached($meta, $snapshot), entityId: $id));
    }

    /**
     * Query-builder writes (insert / update / delete with callListeners): before events, the statement, after
     * events. Inserts get one event per row with the row values; update gets the SET values; delete none.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $values
     * @param Closure(): int $run
     */
    public function write(string $type, EntityMetadata $meta, array $values, Closure $run): int
    {
        $fire = function (string $when) use ($type, $meta, $values): void {
            match ($type) {
                'insert' => array_map(fn (array $row) => $this->insert($when . 'Insert', $meta, $row), $values),
                'update' => $this->update($when . 'Update', $meta, $values),
                default => $this->remove($when . 'Remove', $meta, null),
            };
        };
        $fire('before');
        $affected = $run();
        $fire('after');

        return $affected;
    }

    // ---- AfterLoad ------------------------------------------------------------------------------------

    /**
     * Run a load; AfterLoad fires for what it hydrated once the outermost load returns.
     *
     * @template T
     * @param Closure(): T $work returns the loaded entity / entities (the roots of the graph walk)
     * @return T
     */
    public function loading(Closure $work): mixed
    {
        $this->loadDepth++;
        try {
            $result = $work();
        } catch (\Throwable $e) {
            if (--$this->loadDepth === 0) {
                $this->loaded = new SplObjectStorage;
            }
            throw $e;
        }
        if (--$this->loadDepth === 0 && $this->loaded->count() > 0) {
            $pending = $this->loaded;
            $this->loaded = new SplObjectStorage; // listeners may load again
            $this->flush($pending, is_array($result) ? $result : (is_object($result) ? [$result] : []));
        }

        return $result;
    }

    /** Called by the Hydrator for every entity it builds. */
    public function hydrated(object $entity, EntityMetadata $meta): void
    {
        if (! $this->wantsAfterLoad($meta->class)) {
            return;
        }
        if ($this->loadDepth > 0) {
            $this->loaded->offsetSet($entity, $meta);

            return;
        }
        $this->afterLoad($entity, $meta);
    }

    private function wantsAfterLoad(string $class): bool
    {
        return EntityListeners::has($class, 'afterLoad') || $this->subscribersFor($class, 'afterLoad') !== [];
    }

    /** @param SplObjectStorage<object, EntityMetadata> $pending @param list<mixed> $roots */
    private function flush(SplObjectStorage $pending, array $roots): void
    {
        $visited = new SplObjectStorage;
        foreach ($roots as $root) {
            if (is_object($root)) {
                $this->visit($root, $pending, $visited);
            }
        }
        foreach ($pending as $entity) { // hydrated but not reachable from the result (e.g. replaced relations)
            $this->afterLoad($entity, $pending[$entity]);
        }
    }

    /** TypeORM's order: the loaded relations of an entity first, then the entity itself. */
    private function visit(object $entity, SplObjectStorage $pending, SplObjectStorage $visited): void
    {
        if ($visited->offsetExists($entity) || Persister::isUninitializedLazy($entity)) {
            return;
        }
        $visited->offsetSet($entity, true);
        if (MetadataRegistry::has($entity::class)) {
            $meta = MetadataRegistry::get($entity::class);
            foreach ($meta->relations as $relation) {
                if (! $meta->isInitialized($entity, $relation->property)) {
                    continue;
                }
                $value = $entity->{$relation->property};
                if ($value instanceof LazyCollection) {
                    $value = $value->isLoaded() ? $value->toArray() : [];
                }
                foreach (is_iterable($value) ? $value : [$value] as $related) {
                    if (is_object($related)) {
                        $this->visit($related, $pending, $visited);
                    }
                }
            }
        }
        if ($pending->offsetExists($entity)) {
            $meta = $pending[$entity];
            $pending->offsetUnset($entity);
            $this->afterLoad($entity, $meta);
        }
    }

    private function afterLoad(object $entity, EntityMetadata $meta): void
    {
        EntityListeners::call($entity, 'afterLoad');
        $subscribers = $this->subscribersFor($meta->class, 'afterLoad');
        if ($subscribers !== []) {
            $event = new LoadEvent(...$this->context(), entity: $entity, metadata: $meta);
            foreach ($subscribers as $subscriber) {
                $subscriber->afterLoad($entity, $event);
            }
        }
    }

    // ---- connection events ----------------------------------------------------------------------------

    private function transactionPhase(string $phase, int $depth): void
    {
        $subscribers = $this->subscribersFor(null, $phase);
        if ($subscribers === []) {
            return;
        }
        $class = self::TRANSACTION_EVENTS[$phase];
        $event = new $class(...$this->context());
        foreach ($subscribers as $subscriber) {
            $subscriber->{$phase}($event);
        }
    }

    private function beforeQuery(string $sql, array $params): void
    {
        if ($this->inQueryEvent || $this->dataSource->subscribers === []) {
            return;
        }
        $this->queryEvent('beforeQuery', fn (array $c) => new BeforeQueryEvent(...$c, query: $sql, parameters: $params));
    }

    private function afterQuery(string $sql, array $params, bool $success, float $time, mixed $stmt, ?\Throwable $error): void
    {
        if ($this->inQueryEvent || $this->dataSource->subscribers === []) {
            return;
        }
        $this->queryEvent('afterQuery', fn (array $c) => new AfterQueryEvent(
            ...$c,
            query: $sql,
            parameters: $params,
            success: $success,
            executionTime: $time,
            rawResults: $stmt instanceof \PDOStatement ? $stmt : null,
            error: $error,
        ));
    }

    /** @param Closure(array): OrmEvent $make */
    private function queryEvent(string $method, Closure $make): void
    {
        $subscribers = $this->subscribersFor(null, $method);
        if ($subscribers === []) {
            return;
        }
        $this->inQueryEvent = true; // statements run by the subscriber do not re-enter
        try {
            $event = $make($this->context());
            foreach ($subscribers as $subscriber) {
                $subscriber->{$method}($event);
            }
        } finally {
            $this->inQueryEvent = false;
        }
    }

    // ---- helpers --------------------------------------------------------------------------------------

    /** @param Closure(array): OrmEvent $make */
    private function dispatch(string $event, EntityMetadata $meta, mixed $entity, Closure $make): void
    {
        if (is_object($entity) && $entity instanceof $meta->class) {
            EntityListeners::call($entity, $event);
        }
        $subscribers = $this->subscribersFor($meta->class, $event);
        if ($subscribers === []) {
            return;
        }
        $payload = $make($this->context());
        foreach ($subscribers as $subscriber) {
            $subscriber->{$event}($payload);
        }
    }

    /** @return array{connection: DataSource, queryRunner: \Nesto\Orm\QueryRunner, manager: \Nesto\Orm\EntityManager} */
    private function context(): array
    {
        $runner = $this->dataSource->currentQueryRunner();

        return ['connection' => $this->dataSource, 'queryRunner' => $runner, 'manager' => $runner->manager];
    }

    /**
     * Subscribers defining $method that listen to $class (or to every entity); $class null: every subscriber
     * defining $method (transaction / query events).
     *
     * @return list<object>
     */
    private function subscribersFor(?string $class, string $method): array
    {
        $out = [];
        foreach ($this->dataSource->subscribers as $subscriber) {
            if (! is_object($subscriber) || isset($out[spl_object_id($subscriber)]) || ! is_callable([$subscriber, $method])) {
                continue;
            }
            if ($class !== null && is_callable([$subscriber, 'listenTo'])) {
                $target = $subscriber->listenTo();
                if (is_string($target) && $target !== '' && ! is_a($class, $target, true)) {
                    continue;
                }
            }
            $out[spl_object_id($subscriber)] = $subscriber;
        }

        return array_values($out);
    }

    /** A detached entity holding a row's values (UpdateEvent / RemoveEvent databaseEntity). @param array<string, mixed> $row */
    private static function detached(EntityMetadata $meta, array $row): object
    {
        $entity = (new ReflectionClass($meta->class))->newInstanceWithoutConstructor();
        foreach ($meta->columns as $column) {
            if ($column->property === null || ! array_key_exists($column->name, $row)) {
                continue;
            }
            $value = TypeConverter::toPhp($column, $row[$column->name]);
            if ($value !== null || Hydrator::allowsNull($entity, $column->property)) {
                $entity->{$column->property} = $value;
            }
        }

        return $entity;
    }
}
