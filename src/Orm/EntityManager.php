<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Closure;
use WeakReference;

/**
 * Entity-agnostic facade over the repositories (TypeORM's EntityManager).
 *
 *   $em->save($user);                                   // any entity (or list of entities)
 *   $em->find(User::class, ['where' => ['active' => true]]);
 *   $em->findOne(Post::class, ['where' => ['id' => 3], 'relations' => ['tags']]);
 *   $em->transaction(fn (EntityManager $tx) => ...);    // $tx: the transactional manager
 *   $em->transaction('SERIALIZABLE', fn (EntityManager $tx) => ...);
 *   $em->upsert(User::class, [['email' => 'a@x.io', 'name' => 'A']], ['email']);
 *   $em->createQueryBuilder(User::class, 'u')->getMany();
 *   $em->query('SELECT COUNT(*) AS n FROM users WHERE active = :a', ['a' => true]);
 *
 * The manager of a QueryRunner (`$queryRunner->manager`, the one transaction() passes) has `queryRunner` set and
 * hands out repositories bound to it; `withRepository($customRepository)` rebinds a custom repository to it.
 *
 * @property-read ?QueryRunner $queryRunner the runner this manager is bound to (null for the DataSource's manager)
 */
final class EntityManager
{
    /** @var WeakReference<QueryRunner>|null weak: the runner owns its manager, not the other way round */
    private readonly ?WeakReference $runner;
    /** @var array<class-string, Repository> repositories bound to this runner's manager */
    private array $repositories = [];

    public function __construct(private readonly DataSource $dataSource, ?QueryRunner $queryRunner = null)
    {
        $this->runner = $queryRunner !== null ? WeakReference::create($queryRunner) : null;
    }

    public function __get(string $name): mixed
    {
        if ($name === 'queryRunner') {
            return $this->getQueryRunner();
        }
        if ($name === 'connection' || $name === 'dataSource') {
            return $this->dataSource;
        }
        throw new OrmException('Undefined property ' . self::class . "::\$$name.");
    }

    public function __isset(string $name): bool
    {
        return $name === 'queryRunner' ? $this->getQueryRunner() !== null : in_array($name, ['connection', 'dataSource'], true);
    }

    public function getQueryRunner(): ?QueryRunner
    {
        return $this->runner?->get();
    }

    public function getDataSource(): DataSource
    {
        return $this->dataSource;
    }

    public function getConnection(): Connection
    {
        return $this->dataSource->getConnection();
    }

    /** @param class-string $entity */
    public function getRepository(string $entity): Repository
    {
        if ($this->runner === null) {
            return $this->dataSource->getRepository($entity);
        }

        return $this->repositories[$entity] ??= $this->dataSource->getRepository($entity)->withManager($this);
    }

    /** TypeORM's manager.getTreeRepository(): the TreeRepository of a #[Tree] entity, bound to this manager. @param class-string $entity */
    public function getTreeRepository(string $entity): TreeRepository
    {
        $this->dataSource->getTreeRepository($entity); // validates the entity is a tree

        return $this->getRepository($entity);
    }

    /**
     * A custom repository bound to this manager (TypeORM's withRepository()): inside a transaction its
     * `manager` is the transactional one.
     *
     * @template R of Repository
     * @param R $repository
     * @return R
     */
    public function withRepository(Repository $repository): Repository
    {
        return $repository->manager === $this ? $repository : $repository->withManager($this);
    }

    /** @param class-string $entity */
    public function create(string $entity, array $plain = []): object
    {
        return $this->getRepository($entity)->create($plain);
    }

    /** @param class-string $entity */
    public function merge(string $entity, object $target, array ...$plains): object
    {
        $repository = $this->getRepository($entity);
        foreach ($plains as $plain) {
            $repository->merge($target, $plain);
        }

        return $target;
    }

    /** @param class-string $entity */
    public function preload(string $entity, array $plain): ?object
    {
        return $this->getRepository($entity)->preload($plain);
    }

    /**
     * @template T of object
     * @param T|list<T> $entities
     * @param array{listeners?: bool, transaction?: bool, data?: array, chunk?: int, reload?: bool} $options (see Repository::save())
     * @return T|list<T>
     */
    public function save(object|array $entities, array $options = []): object|array
    {
        if (is_array($entities)) {
            $list = array_values($entities);
            $this->dataSource->persister()->run('save', $list, $options);

            return $entities;
        }

        return $this->getRepository($entities::class)->save($entities, $options);
    }

    /** @param array{listeners?: bool, transaction?: bool, data?: array, chunk?: int} $options */
    public function remove(object|array $entities, array $options = []): object|array
    {
        if (is_array($entities)) {
            $list = array_values($entities);
            $this->dataSource->persister()->run('remove', $list, $options);

            return $entities;
        }

        return $this->getRepository($entities::class)->remove($entities, $options);
    }

    /** Soft-delete entities (DeleteDateColumn), cascading "soft-remove". */
    public function softRemove(object|array $entities, array $options = []): object|array
    {
        if (is_array($entities)) {
            $list = array_values($entities);
            $this->dataSource->persister()->run('softRemove', $list, $options);

            return $entities;
        }

        return $this->getRepository($entities::class)->softRemove($entities, $options);
    }

    /** Undo softRemove(), cascading "recover". */
    public function recover(object|array $entities, array $options = []): object|array
    {
        if (is_array($entities)) {
            $list = array_values($entities);
            $this->dataSource->persister()->run('recover', $list, $options);

            return $entities;
        }

        return $this->getRepository($entities::class)->recover($entities, $options);
    }

    /**
     * INSERT … ON CONFLICT DO UPDATE (see Repository::upsert()).
     *
     * @param class-string $entity
     * @param list<string>|array{conflictPaths: list<string>|array<string, bool>, skipUpdateIfNoValuesChanged?: bool, upsertType?: string, indexPredicate?: string} $conflictPathsOrOptions
     */
    public function upsert(string $entity, object|array $entities, array $conflictPathsOrOptions): InsertResult
    {
        return $this->getRepository($entity)->upsert($entities, $conflictPathsOrOptions);
    }

    /** @param class-string $entity @return list<object> */
    public function find(string $entity, array $options = []): array
    {
        return $this->getRepository($entity)->find($options);
    }

    /** @param class-string $entity @return list<object> */
    public function findBy(string $entity, array $where): array
    {
        return $this->getRepository($entity)->findBy($where);
    }

    /** @param class-string $entity @return array{0: list<object>, 1: int} */
    public function findAndCount(string $entity, array $options = []): array
    {
        return $this->getRepository($entity)->findAndCount($options);
    }

    /** @param class-string $entity */
    public function findOne(string $entity, array $options = []): ?object
    {
        return $this->getRepository($entity)->findOne($options);
    }

    /** @param class-string $entity */
    public function findOneOrFail(string $entity, array $options = []): object
    {
        return $this->getRepository($entity)->findOneOrFail($options);
    }

    /** @param class-string $entity */
    public function findOneBy(string $entity, array $where): ?object
    {
        return $this->getRepository($entity)->findOneBy($where);
    }

    /** @param class-string $entity */
    public function findOneByOrFail(string $entity, array $where): object
    {
        return $this->getRepository($entity)->findOneByOrFail($where);
    }

    /** @param class-string $entity */
    public function count(string $entity, array $options = []): int
    {
        return $this->getRepository($entity)->count($options);
    }

    /** @param class-string $entity */
    public function exists(string $entity, array $options = []): bool
    {
        return $this->getRepository($entity)->exists($options);
    }

    /** @param class-string $entity */
    public function sum(string $entity, string $column, array $where = []): ?float
    {
        return $this->getRepository($entity)->sum($column, $where);
    }

    /** @param class-string $entity */
    public function average(string $entity, string $column, array $where = []): ?float
    {
        return $this->getRepository($entity)->average($column, $where);
    }

    /** @param class-string $entity */
    public function minimum(string $entity, string $column, array $where = []): mixed
    {
        return $this->getRepository($entity)->minimum($column, $where);
    }

    /** @param class-string $entity */
    public function maximum(string $entity, string $column, array $where = []): mixed
    {
        return $this->getRepository($entity)->maximum($column, $where);
    }

    /** @param class-string $entity */
    public function insert(string $entity, array $values): int|string|array|null
    {
        return $this->getRepository($entity)->insert($values);
    }

    /** @param class-string $entity */
    public function update(string $entity, int|string|array|null $criteria, array $values): int
    {
        return $this->getRepository($entity)->update($criteria, $values);
    }

    /** @param class-string $entity */
    public function delete(string $entity, int|string|array|null $criteria): int
    {
        return $this->getRepository($entity)->delete($criteria);
    }

    public function softDelete(string $entity, int|string|array|object|null $target): int
    {
        return $this->getRepository($entity)->softDelete($target);
    }

    public function restore(string $entity, int|string|array|object|null $target): int
    {
        return $this->getRepository($entity)->restore($target);
    }

    /** @param class-string $entity */
    public function increment(string $entity, int|string|array|null $criteria, string $property, int|float $by = 1): int
    {
        return $this->getRepository($entity)->increment($criteria, $property, $by);
    }

    /** @param class-string $entity */
    public function decrement(string $entity, int|string|array|null $criteria, string $property, int|float $by = 1): int
    {
        return $this->getRepository($entity)->decrement($criteria, $property, $by);
    }

    /**
     * Run $fn(EntityManager $transactional) in a transaction (TypeORM's manager.transaction()): committed when it
     * returns, rolled back when it throws. Called on a QueryRunner's manager, it runs on that runner (nested
     * calls become savepoints); on the DataSource's manager, on a new QueryRunner released afterwards.
     *
     *   $em->transaction(fn (EntityManager $tx) => $tx->save($order));
     *   $em->transaction('SERIALIZABLE', fn (EntityManager $tx) => ...);
     *
     * @param Closure(EntityManager): mixed|string $isolationLevelOrFn
     */
    public function transaction(Closure|string $isolationLevelOrFn, ?Closure $fn = null): mixed
    {
        [$isolation, $fn] = $isolationLevelOrFn instanceof Closure ? [null, $isolationLevelOrFn] : [$isolationLevelOrFn, $fn];
        if ($fn === null) {
            throw new OrmException('transaction() needs a callback: transaction($fn) or transaction($isolationLevel, $fn).');
        }
        $own = $this->getQueryRunner();
        if ($this->runner !== null && $own === null) {
            throw new QueryRunnerAlreadyReleasedException;
        }
        $runner = $own ?? $this->dataSource->createQueryRunner();
        try {
            $runner->startTransaction($isolation);
            try {
                $result = $fn($runner->manager);
            } catch (\Throwable $e) {
                try {
                    $runner->rollbackTransaction();
                } catch (\Throwable) {
                    // the original error matters more than a failed rollback
                }
                throw $e;
            }
            $runner->commitTransaction();

            return $result;
        } finally {
            if ($own === null) {
                $runner->release();
            }
        }
    }

    /** A QueryBuilder running on this manager's QueryRunner (if any; TypeORM's manager.createQueryBuilder()). */
    public function createQueryBuilder(?string $entityOrTable = null, ?string $alias = null): QueryBuilder
    {
        return $this->dataSource->createQueryBuilder($entityOrTable, $alias)->setQueryRunner($this->getQueryRunner());
    }

    /**
     * Raw SQL with bound parameters; returns rows for SELECT, affected row count otherwise. Runs on this
     * manager's QueryRunner, else on the master (also with replication, like TypeORM).
     */
    public function query(string $sql, array $params = []): array|int
    {
        $stmt = ($this->getQueryRunner()?->connection() ?? $this->getConnection())->query($sql, $params);
        if ($stmt->columnCount() > 0) {
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        return $stmt->rowCount();
    }
}
