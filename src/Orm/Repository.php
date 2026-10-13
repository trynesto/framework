<?php

declare(strict_types=1);

namespace Nesto\Orm;

use DateTimeImmutable;
use Nesto\Orm\Mapping\TypeConverter;

/**
 * Generic repository for one entity class (TypeORM's Repository<T>).
 *
 *   $users = $dataSource->getRepository(User::class);
 *   $user  = $users->create(['name' => 'Ada', 'email' => 'ada@x.io']);   // hydrated, not saved
 *   $users->save($user);                                                 // INSERT, fills $user->id
 *   $user->name = 'Ada L.'; $users->save($user);                         // UPDATE of changed columns only
 *   $users->find(['where' => ['active' => true, 'age' => ['>', 18]], 'order' => ['name' => 'ASC'],
 *                 'take' => 10, 'skip' => 0, 'relations' => ['posts', 'posts.tags']]);
 *   $users->findOneByOrFail(['email' => 'ada@x.io']);                   // 404 when missing
 *   $users->paginate(page: 2, perPage: 20);
 *   $users->softDelete($id); $users->restore($id); $users->delete(['active' => false]);
 *   $users->upsert([['email' => 'a@x.io', 'name' => 'A']], ['email']);  // INSERT … ON CONFLICT DO UPDATE
 *   $users->preload(['id' => 3, 'name' => 'New']);                      // load + merge, null when missing
 *   $users->sum('age', ['active' => true]); $users->average(...); $users->minimum(...); $users->maximum(...);
 *   $users->findOne(['where' => ['id' => 1], 'lock' => ['mode' => 'optimistic', 'version' => 3]]);
 *   $users->save($user, ['listeners' => false, 'transaction' => false]);
 *
 * Custom repositories extend this class (see Attributes\CustomRepository); `manager` is the EntityManager the
 * repository belongs to (the transactional one for `$tx->getRepository()` / `$tx->withRepository($repo)`).
 *
 * Find options (see FindOptions): where (incl. relation columns), order, select, relations,
 * relationLoadStrategy, loadEagerRelations, loadRelationIds, take, skip, withDeleted.
 * Relations marked `eager: true` are loaded by every find*() unless loadEagerRelations is false.
 */
class Repository
{
    public const OPTION_KEYS = FindOptions::KEYS;

    /** @var class-string */
    public readonly string $entity;
    /** The EntityManager this repository belongs to (a transactional one through `$tx->getRepository()` / withRepository()). */
    public readonly EntityManager $manager;
    private readonly EntityMetadata $meta;
    private static ?EntityManager $cloneManager = null;

    /**
     * @param class-string|null $entity null in a custom repository marked #[CustomRepository(Entity::class)]:
     *                                  `#[CustomRepository(User::class)] class UserRepository extends Repository {}`
     */
    public function __construct(private readonly DataSource $dataSource, ?string $entity = null, ?EntityManager $manager = null)
    {
        $this->entity = $entity ?? Attributes\CustomRepository::entityOf(static::class);
        $this->meta = MetadataRegistry::get($this->entity);
        $this->manager = $manager ?? $dataSource->getEntityManager();
    }

    /** This repository bound to another manager (EntityManager::withRepository()); custom methods are kept. */
    public function withManager(EntityManager $manager): static
    {
        self::$cloneManager = $manager;
        try {
            return clone $this;
        } finally {
            self::$cloneManager = null;
        }
    }

    public function __clone()
    {
        if (self::$cloneManager !== null) {
            $this->manager = self::$cloneManager; // readonly properties may be re-set while cloning (PHP 8.3)
        }
    }

    /** TypeORM's repository.queryRunner: the runner of its manager (null for the DataSource's repositories). */
    public function getQueryRunner(): ?QueryRunner
    {
        return $this->manager->getQueryRunner();
    }

    public function metadata(): EntityMetadata
    {
        return $this->meta;
    }

    public function getConnection(): Connection
    {
        return $this->dataSource->getConnection();
    }

    public function getDataSource(): DataSource
    {
        return $this->dataSource;
    }

    /** A QueryBuilder for the entity, running on the repository's QueryRunner when it has one (TypeORM). */
    public function createQueryBuilder(string $alias = 'e', ?QueryRunner $queryRunner = null): QueryBuilder
    {
        return (new QueryBuilder($this->dataSource, $this->entity, $alias))->setQueryRunner($queryRunner ?? $this->manager->getQueryRunner());
    }

    private function findOptions(array $options): FindOptions
    {
        return new FindOptions($this->dataSource, $this->meta, $options, $this->manager->getQueryRunner());
    }

    /**
     * Run a load with all its reads (root query, relations, relation ids) on one node: the repository's
     * QueryRunner, or the DataSource's read connection (a replica with replication).
     */
    private function reading(\Closure $fn): mixed
    {
        if (! $this->dataSource->isReplicated()) {
            return $fn();
        }

        return $this->dataSource->readingFrom($this->dataSource->readConnection($this->manager->getQueryRunner()), $fn);
    }

    // ---- reading --------------------------------------------------------------------------------

    /** @return list<object> */
    public function find(array $options = []): array
    {
        $find = $this->findOptions($options);

        // AfterLoad fires once the relations are loaded too
        return $this->reading(fn (): array => $this->dataSource->broadcaster()->loading(function () use ($find): array {
            $entities = $find->queryBuilder()->getMany();
            $find->load($entities);

            return $entities;
        }));
    }

    public function findOne(array $options = []): ?object
    {
        $find = $this->findOptions($options);

        return $this->reading(fn (): ?object => $this->dataSource->broadcaster()->loading(function () use ($find): ?object {
            $entity = $find->queryBuilder()->getOne();
            if ($entity !== null) {
                $find->load([$entity]);
            }

            return $entity;
        }));
    }

    public function findOneOrFail(array $options = []): object
    {
        return $this->findOne($options) ?? throw EntityNotFoundException::for($this->entity, $options['where'] ?? null);
    }

    /** @return list<object> */
    public function findBy(array $where, array $options = []): array
    {
        return $this->find(['where' => $where] + $options);
    }

    public function findOneBy(array $where, array $options = []): ?object
    {
        return $this->findOne(['where' => $where] + $options);
    }

    public function findOneByOrFail(array $where, array $options = []): object
    {
        return $this->findOneBy($where, $options) ?? throw EntityNotFoundException::for($this->entity, $where);
    }

    /** Find by primary key. */
    public function findById(int|string $id, array $options = []): ?object
    {
        return $this->findOne(['where' => [(string) $this->meta->primary->property => $id]] + $options);
    }

    public function findByIdOrFail(int|string $id, array $options = []): object
    {
        return $this->findById($id, $options) ?? throw EntityNotFoundException::for($this->entity, [(string) $this->meta->primary->property => $id]);
    }

    /**
     * find() as a generator for big results: rows are streamed (see QueryBuilder::stream()) and hydrated
     * $batchSize entities at a time, the batch's relations loaded per batch. Same options as find().
     *
     *   foreach ($events->findIterable(['where' => ['day' => $day], 'relations' => ['venue']]) as $event) { ... }
     *
     * @return \Generator<int, object>
     */
    public function findIterable(array $options = [], int $batchSize = 100): \Generator
    {
        $find = $this->findOptions($options);

        yield from $find->queryBuilder()->getManyIterable($batchSize, fn (array $batch) => $find->load($batch));
    }

    /**
     * Process the matching entities $size at a time (pages of find(), ordered by primary key unless `order`
     * is given): $callback(list $entities, int $page) — return false to stop. Returns false when stopped.
     * Changing the rows' match of `where` while chunking shifts the pages (use findIterable() then).
     *
     *   $users->chunk(500, function (array $users): void { foreach ($users as $u) { ... } }, ['where' => ['active' => true]]);
     */
    public function chunk(int $size, \Closure $callback, array $options = []): bool
    {
        $size = max(1, $size);
        for ($page = 0; ; $page++) {
            $batch = $this->find(['take' => $size, 'skip' => $page * $size] + $options);
            if ($batch === []) {
                return true;
            }
            if ($callback($batch, $page) === false) {
                return false;
            }
            if (count($batch) < $size) {
                return true;
            }
        }
    }

    /** @return array{0: list<object>, 1: int} */
    public function findAndCount(array $options = []): array
    {
        return [$this->find($options), $this->count($options)];
    }

    /** @return array{0: list<object>, 1: int} */
    public function findAndCountBy(array $where): array
    {
        return $this->findAndCount(['where' => $where]);
    }

    public function count(array $options = []): int
    {
        unset($options['lock']); // aggregates cannot be locked (FOR UPDATE with COUNT fails on PostgreSQL)

        return $this->findOptions($options)->queryBuilder(forCount: true)->getCount();
    }

    /** SUM of a numeric column over the rows matching $where (TypeORM's sum()); null when no row matches. */
    public function sum(string $column, array $where = []): ?float
    {
        $value = $this->aggregate('SUM', $column, $where);

        return $value === null ? null : (float) $value;
    }

    /** AVG of a numeric column (TypeORM's average()); null when no row matches. */
    public function average(string $column, array $where = []): ?float
    {
        $value = $this->aggregate('AVG', $column, $where);

        return $value === null ? null : (float) $value;
    }

    /** MIN of a column (TypeORM's minimum()), converted like the property; null when no row matches. */
    public function minimum(string $column, array $where = []): mixed
    {
        return $this->convertAggregate($column, $this->aggregate('MIN', $column, $where));
    }

    /** MAX of a column (TypeORM's maximum()), converted like the property; null when no row matches. */
    public function maximum(string $column, array $where = []): mixed
    {
        return $this->convertAggregate($column, $this->aggregate('MAX', $column, $where));
    }

    public function countBy(array $where): int
    {
        return $this->count(['where' => $where]);
    }

    public function exists(array $options = []): bool
    {
        return $this->count($options) > 0;
    }

    public function existsBy(array $where): bool
    {
        return $this->exists(['where' => $where]);
    }

    public function paginate(int $page = 1, int $perPage = 15, array $options = []): Pagination
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $total = $this->count($options);
        $data = $this->find(['take' => $perPage, 'skip' => ($page - 1) * $perPage] + $options);

        return new Pagination($data, $total, $page, $perPage);
    }

    // ---- hydration ------------------------------------------------------------------------------

    /** Build an unsaved entity from a plain array (property or column names; scalars are type-converted). */
    public function create(array $plain = []): object
    {
        return $this->dataSource->hydrator()->fromPlain($this->meta, $plain);
    }

    /** Copy plain values onto an entity and return it (several plain arrays are merged in order). */
    public function merge(object $entity, array ...$plains): object
    {
        foreach ($plains as $plain) {
            $this->dataSource->hydrator()->merge($this->meta, $entity, $plain);
        }

        return $entity;
    }

    /**
     * TypeORM's preload(): load the entity whose primary key $plain carries (with the relations it names) and
     * merge $plain over it, ready for save(). Null when it has no key or the row does not exist.
     *
     *   $user = $users->preload(['id' => 3, 'name' => 'New name']) ?? throw new NotFoundException;
     */
    public function preload(array $plain): ?object
    {
        $primary = $this->meta->primary;
        $id = $plain[(string) $primary->property] ?? $plain[$primary->name] ?? null;
        if ($id === null || $id === '') {
            return null;
        }
        $relations = [];
        foreach (array_keys($plain) as $key) {
            if (($relation = $this->meta->relation((string) $key)) !== null && ! $relation->isReadOnly()) {
                $relations[] = (string) $key;
            }
        }
        $entity = $this->findOne(['where' => [(string) $primary->property => $id], 'relations' => $relations, 'loadEagerRelations' => false]);

        return $entity === null ? null : $this->merge($entity, $plain);
    }

    /** Does the entity have a primary key value (TypeORM's hasId())? */
    public function hasId(object $entity): bool
    {
        return $this->meta->primaryValue($entity) !== null;
    }

    /** The entity's primary key value (TypeORM's getId()). */
    public function getId(object $entity): mixed
    {
        return $this->meta->primaryValue($entity);
    }

    /** Raw SQL (TypeORM's repository.query()): rows for statements returning columns, the affected row count otherwise. */
    public function query(string $sql, array $params = []): array|int
    {
        return $this->manager->query($sql, $params);
    }

    // ---- writing --------------------------------------------------------------------------------

    /**
     * Insert new entities / update existing ones (changed columns only), TypeORM's save(): fills generated
     * ids and date columns and walks the relations per their `cascade` options (see Persistence\Persister):
     * owning to-one targets are saved first, OneToMany / inverse OneToOne children get this entity's key
     * (rows removed from a loaded collection get the ManyToOne's orphanedRowAction), ManyToMany join rows
     * are diffed from either side. Polymorphic children / targets are always saved; through relations never.
     *
     * An entity with a key that was never loaded (create(['id' => 5, …]) / `new`) is read first (one SELECT):
     * only the columns it sets are written — class defaults it was not given keep the stored values — and it is
     * inserted when no row has that key (TypeORM's save()).
     *
     * Options (TypeORM's SaveOptions, see Persistence\Persister::run()): `listeners` (false: no entity listeners /
     * subscribers), `transaction` (false: no transaction of its own; one is opened only when none is active
     * anyway), `data` (for subscribers: $event->queryRunner->data), `chunk` (save in groups of N), `reload`
     * (false: generated values are not read back, except through PostgreSQL's RETURNING).
     *
     * @template T of object
     * @param T|list<T> $entities
     * @param array{listeners?: bool, transaction?: bool, data?: array, chunk?: int, reload?: bool} $options
     * @return T|list<T>
     */
    public function save(object|array $entities, array $options = []): object|array
    {
        $list = is_array($entities) ? array_values($entities) : [$entities];
        $this->dataSource->persister()->run('save', $list, $options);

        return $entities;
    }

    /**
     * INSERT … ON CONFLICT DO UPDATE (TypeORM's upsert()): rows whose conflict columns match an existing row
     * update it, the others are inserted. Entities or plain arrays; generated ids are written back onto the
     * given entities. MySQL / MariaDB use ON DUPLICATE KEY UPDATE (any unique key conflicts there).
     *
     *   $users->upsert([['email' => 'a@x.io', 'name' => 'A']], ['email']);
     *   $users->upsert($user, ['conflictPaths' => ['email'], 'skipUpdateIfNoValuesChanged' => true]);
     *   $users->upsert($rows, ['conflictPaths' => ['email'], 'indexPredicate' => 'deleted_at IS NULL']);   // PostgreSQL partial index
     *
     * @param list<string>|array{conflictPaths: list<string>|array<string, bool>, skipUpdateIfNoValuesChanged?: bool, upsertType?: string, indexPredicate?: string} $conflictPathsOrOptions
     */
    public function upsert(object|array $entities, array $conflictPathsOrOptions): InsertResult
    {
        return (new Persistence\Upserter($this->dataSource, $this->meta))->upsert($entities, $conflictPathsOrOptions);
    }

    /**
     * Insert plain rows (property or column names) without hydrating entities.
     * Returns the generated id (single row) or the list of ids (list of rows).
     *
     * @param array<string, mixed>|list<array<string, mixed>> $values
     */
    public function insert(array $values): int|string|array|null
    {
        if ($values !== [] && array_is_list($values)) {
            return $this->dataSource->runInTransaction(fn () => array_map(fn (array $row) => $this->insert($row), $values));
        }
        $events = $this->dataSource->broadcaster();
        $events->insert('beforeInsert', $this->meta, $values);
        $row = [];
        foreach ($values as $key => $value) {
            $relation = Mapping\RelationValues::columns($this->meta, (string) $key, $value, 'insert()');
            if ($relation !== null) {
                $row = array_merge($row, $relation);
                continue;
            }
            $column = $this->meta->column((string) $key);
            $row[$this->meta->columnName((string) $key)] = $column !== null ? TypeConverter::toDatabase($column, $value) : TypeConverter::toParameter($value);
        }
        foreach ([$this->meta->createdAt, $this->meta->updatedAt] as $prop) {
            if ($prop !== null && ! array_key_exists($this->meta->columnName($prop), $row)) {
                $row[$this->meta->columnName($prop)] = TypeConverter::toDatabase($this->meta->column($prop), new DateTimeImmutable);
            }
        }
        foreach ($this->meta->columns as $column) {
            // uuid values: the ORM generates them, except on PostgreSQL (its column default does)
            if ($column->generationStrategy === 'uuid' && empty($row[$column->name]) && $this->getConnection()->driver() !== 'pgsql') {
                $row[$column->name] = self::uuid();
            }
        }
        if (($version = $this->meta->versionColumn()) !== null && ! isset($row[$version->name])) {
            $row[$version->name] = 1;
        }
        $row = Persistence\GeneratedValues::insertRow($this->meta, $row);
        $id = $this->dataSource->persister()->insertRow($this->meta, $row);
        $events->insert('afterInsert', $this->meta, $values);

        return $id ?? ($row[$this->meta->primary->name] ?? null);
    }

    /**
     * UPDATE by primary key, list of keys or criteria (see criteria()); values are property (or column) => value,
     * a relation takes an entity, an id or ['id' => …] and writes its join column. Returns affected rows.
     * Empty criteria are refused (TypeORM): use the QueryBuilder to update every row.
     */
    public function update(int|string|array|null $criteria, array $values): int
    {
        return $this->createQueryBuilder('e')->update()->set($values)->where($this->criteria($criteria, 'update'))->execute();
    }

    /** Hard DELETE by primary key, list of keys or criteria; empty criteria are refused. Returns affected rows. */
    public function delete(int|string|array|null $criteria): int
    {
        return $this->createQueryBuilder('e')->delete()->where($this->criteria($criteria, 'delete'))->execute();
    }

    /**
     * Hard-delete the given entity (or entities) and forget their state. Relations with cascade "remove" are
     * removed too (children first, targets after); the entity's ManyToMany join-table rows go with it.
     */
    public function remove(object|array $entities, array $options = []): object|array
    {
        $list = is_array($entities) ? array_values($entities) : [$entities];
        $this->dataSource->persister()->run('remove', $list, $options);

        return $entities;
    }

    /**
     * Soft-delete the given entity (or entities): stamps the DeleteDateColumn on the object and the row, and
     * cascades to loaded relations with cascade "soft-remove" (TypeORM's softRemove()).
     */
    public function softRemove(object|array $entities, array $options = []): object|array
    {
        $list = is_array($entities) ? array_values($entities) : [$entities];
        $this->dataSource->persister()->run('softRemove', $list, $options);

        return $entities;
    }

    /** Undo softRemove(): clears the DeleteDateColumn, cascading "recover" (TypeORM's recover()). */
    public function recover(object|array $entities, array $options = []): object|array
    {
        $list = is_array($entities) ? array_values($entities) : [$entities];
        $this->dataSource->persister()->run('recover', $list, $options);

        return $entities;
    }

    /** Stamp the DeleteDateColumn. Accepts an id, a list of ids, criteria or an entity; empty criteria are refused. */
    public function softDelete(int|string|array|object|null $target): int
    {
        $column = $this->meta->deletedAt ?? throw new OrmException("{$this->entity} has no #[DeleteDateColumn]; use delete() instead.");
        $now = new DateTimeImmutable;
        $where = $this->criteria(is_object($target) ? $this->meta->primaryValue($target) : $target, 'softDelete');
        if (is_object($target)) {
            $target->{$column} = $now;
        }

        return $this->createQueryBuilder('e')->update()->set([$column => $now])->where($where)->execute();
    }

    /** Clear the DeleteDateColumn. Accepts an id, a list of ids, criteria or an entity; empty criteria are refused. */
    public function restore(int|string|array|object|null $target): int
    {
        $column = $this->meta->deletedAt ?? throw new OrmException("{$this->entity} has no #[DeleteDateColumn].");
        $where = $this->criteria(is_object($target) ? $this->meta->primaryValue($target) : $target, 'restore');
        if (is_object($target)) {
            $target->{$column} = null;
        }

        return $this->createQueryBuilder('e')->withDeleted()->update()->set([$column => null])->where($where)->execute();
    }

    /** Add $by to a numeric column of the rows matching the criteria (empty criteria are refused). */
    public function increment(int|string|array|null $criteria, string $property, int|float $by = 1): int
    {
        return $this->adjust($this->criteria($criteria, 'increment'), $property, $by);
    }

    /** Subtract $by from a numeric column of the rows matching the criteria (empty criteria are refused). */
    public function decrement(int|string|array|null $criteria, string $property, int|float $by = 1): int
    {
        return $this->adjust($this->criteria($criteria, 'decrement'), $property, -$by);
    }

    /** Reload the entity's columns from the database. */
    public function refresh(object $entity): object
    {
        $fresh = $this->findOne(['where' => [(string) $this->meta->primary->property => $this->meta->primaryValue($entity)], 'withDeleted' => true, 'loadEagerRelations' => false])
            ?? throw EntityNotFoundException::for($this->entity);
        foreach ($this->meta->propertyColumns() as $column) {
            if (Mapping\PropertyAccess::isInitialized($fresh, $column)) {
                Mapping\PropertyAccess::set($entity, $column, Mapping\PropertyAccess::get($fresh, $column));
            }
        }
        $this->dataSource->state()->setSnapshot($entity, $this->dataSource->state()->snapshot($fresh) ?? []);

        return $entity;
    }

    // ---- internals ------------------------------------------------------------------------------

    private function aggregate(string $function, string $column, array $where): mixed
    {
        $meta = $this->meta->column($column) ?? throw new OrmException("{$this->entity} has no column \"$column\".");
        $qb = $this->createQueryBuilder('e')->select(["$function(e.{$meta->name}) AS nesto_value"]);
        if ($where !== []) {
            $qb->where($where);
        }

        return $qb->getRawOne()['nesto_value'] ?? null;
    }

    private function convertAggregate(string $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $converted = TypeConverter::toPhp($this->meta->column($column), $value);

        return is_string($converted) && is_numeric($converted) ? $converted + 0 : $converted;
    }

    /**
     * WHERE criteria of the criteria-based writes (update, delete, softDelete, restore, increment, decrement), as
     * TypeORM takes them: an id, a list of ids (whereInIds), criteria, or a list of criteria (OR-ed). Empty
     * criteria — null, '', [], or a list holding one — would touch every row: refused, with TypeORM's message.
     */
    private function criteria(mixed $criteria, string $method): array
    {
        $empty = static fn (mixed $c): bool => $c === null || $c === '' || $c === [];
        if ($empty($criteria) || (is_array($criteria) && array_is_list($criteria) && array_filter($criteria, $empty) !== [])) {
            throw new OrmException("Empty criteria(s) are not allowed for the $method method.");
        }
        $primary = (string) $this->meta->primary->property;
        if (! is_array($criteria)) {
            return [$primary => $criteria];
        }
        if (! array_is_list($criteria)) {
            return $criteria;
        }
        $lists = count(array_filter($criteria, 'is_array'));
        if ($lists === 0) {
            return [$primary => Operators\Operator::in($criteria)];
        }
        if ($lists !== count($criteria)) {
            throw new OrmException("$method(): the criteria list mixes ids and criteria arrays.");
        }

        return $criteria;
    }

    private function adjust(array $where, string $property, int|float $by): int
    {
        $this->meta->assertWritable($by < 0 ? 'decrement()' : 'increment()');
        $conn = $this->getConnection();
        $column = $conn->quote($this->meta->columnName($property));
        $qb = $this->createQueryBuilder('e')->where($where);
        $whereSql = $qb->delete()->getSql();
        $whereSql = substr($whereSql, (int) strpos($whereSql, ' WHERE '));
        $set = $column . ' = ' . $column . ' + :nesto_by';
        if (($version = $this->meta->versionColumn()) !== null && $version->property !== $property) {
            $set .= ', ' . $conn->quote($version->name) . ' = ' . $conn->quote($version->name) . ' + 1'; // as TypeORM's update query
        }
        $sql = 'UPDATE ' . $conn->quote($this->meta->table) . ' SET ' . $set . $whereSql;

        return $conn->execute($sql, $qb->getParameters() + ['nesto_by' => $by]);
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
