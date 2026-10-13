<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Closure;
use Nesto\Orm\Migration\MigrationGenerator;
use Nesto\Orm\Migration\Migrator;
use Nesto\Orm\Migration\Schema;
use Nesto\Orm\Migration\SchemaSynchronizer;

/**
 * Connection + entity list + repositories (TypeORM's DataSource).
 *
 *   $ds = new DataSource([
 *       'type' => 'sqlite', 'database' => ':memory:',      // or mysql/postgres with host, port, username, password
 *       'entities' => [User::class, Post::class],
 *       'synchronize' => true,                               // diff entities vs database and apply it (dev only)
 *       'logging' => false,                                  // true (queries + errors) | 'all' | ['query', 'error', 'schema', 'warn', 'info', 'log', 'migration'],
 *                                                            // or a Closure(sql, params, seconds)
 *       'logger' => 'advanced-console',                      // | 'simple-console' | 'file' | 'debug' | Logging\Logger | PSR-3 logger
 *       'maxQueryExecutionTime' => 1000,                     // ms: slower queries go to logger->logQuerySlow()
 *       'migrations' => [CreateUsers::class],                // classes, instances or directories
 *       'migrationsTableName' => 'migrations',               // (alias: migrationsTable)
 *       'migrationsTransactionMode' => 'all',                // all | each | none
 *       'migrationsRun' => false,                            // run pending migrations on initialize()
 *       'dropSchema' => false,                               // drop the entity tables on initialize() (tests)
 *       'morphMap' => ['post' => Post::class],               // aliases stored in polymorphic type columns
 *       'relationLoadStrategy' => 'query',                   // default of the find option: 'query' | 'join'
 *       'types' => ['money' => MoneyType::class],            // custom column types (Types\TypeRegistry)
 *       'subscribers' => [AuditSubscriber::class],           // Events\EntitySubscriberInterface classes / instances
 *       'cache' => true,                                     // query result cache (see Cache\QueryResultCacheFactory)
 *       'replication' => ['master' => [...], 'slaves' => [[...]]],   // reads on replicas (see Replication)
 *       'metadataTableName' => 'typeorm_metadata',           // where view definitions are recorded
 *   ]);
 *   $ds->initialize();
 *   $users = $ds->getRepository(User::class);
 *   $categories = $ds->getTreeRepository(Category::class);   // #[Tree] entities (getRepository() returns it too)
 *   $ds->transaction(fn (EntityManager $tx) => $tx->save($user));          // on a QueryRunner; nested → savepoints
 *   $ds->transaction('SERIALIZABLE', fn (EntityManager $tx) => ...);
 *   $qr = $ds->createQueryRunner(); $qr->startTransaction(); ... $qr->commitTransaction(); $qr->release();
 *   $ds->createQueryRunner('slave');                     // replication: a runner on a replica
 *   $ds->queryResultCache?->remove(['users_admins']);    // cached results by id; ->clear()
 *   $ds->subscribers[] = $subscriber;                    // or addSubscriber()
 *   $ds->runMigrations(); $ds->revertLastMigration(); $ds->showMigrations();
 *   $ds->logSchema();                     // SQL synchronize() would run
 *   $ds->generateMigration('AddNickname20260101000000');   // migration class source, null when in sync
 */
final class DataSource
{
    private ?Connection $connection = null;
    /** @var array<class-string, Repository> */
    private array $repositories = [];
    /** @var list<class-string> */
    private array $entities = [];
    private readonly EntityState $state;
    private readonly Hydrator $hydrator;
    /** The DataSource's own EntityManager (TypeORM's dataSource.manager). */
    public readonly EntityManager $manager;
    /** TypeORM's dataSource.queryResultCache: where `cache`d query results live (null without the `cache` option). */
    public readonly ?Cache\QueryResultCache $queryResultCache;
    /** TypeORM's dataSource.logger, built from the `logger` / `logging` / `maxQueryExecutionTime` options (see Logging\LoggerFactory). */
    public readonly Logging\Logger $logger;
    /**
     * Registered entity subscribers (TypeORM's dataSource.subscribers): the @nestjs/typeorm pattern
     * `$dataSource->subscribers[] = $this;` in a provider's constructor works as is.
     *
     * @var list<Events\EntitySubscriberInterface>
     */
    public array $subscribers = [];
    private ?Persistence\Persister $persister = null;
    private readonly Events\Broadcaster $broadcaster;
    /** @var list<\WeakReference<QueryRunner>> runners with an open transaction, innermost last */
    private array $runners = [];
    private ?QueryRunner $defaultRunner = null;
    private bool $initialized = false;
    private bool $synchronized = false;
    /** The `replication` option: master / slave nodes (null without replication). */
    private readonly ?Replication $replication;
    /** Read connection pinned for the duration of one load (find + its relation queries), see readingFrom(). */
    private ?Connection $pinnedRead = null;

    /** @param array<string, mixed> $options */
    public function __construct(private array $options = [])
    {
        $this->state = new EntityState;
        $this->hydrator = new Hydrator($this->state);
        $this->hydrator->setLazyRelations(new LazyRelations($this));
        $this->manager = new EntityManager($this);
        $this->logger = Logging\LoggerFactory::fromOptions($options);
        $this->replication = Replication::fromOptions($options);
        $this->queryResultCache = Cache\QueryResultCacheFactory::create($this, $options['cache'] ?? null);
        $this->broadcaster = new Events\Broadcaster($this);
        $this->hydrator->onHydrate($this->broadcaster->hydrated(...));
        if (! empty($options['morphMap'])) {
            MorphMap::map($options['morphMap']);
        }
        foreach ($options['types'] ?? [] as $name => $type) {
            Types\TypeRegistry::register((string) $name, $type);
        }
        // declare every entity class first: single table inheritance roots find their #[ChildEntity] classes
        Mapping\Inheritance::register(array_values(array_filter($options['entities'] ?? [], 'is_string')));
        foreach ($options['entities'] ?? [] as $entity) {
            $this->addEntity($entity);
        }
        foreach ($options['subscribers'] ?? [] as $subscriber) {
            $this->addSubscriber(is_string($subscriber) ? new $subscriber : $subscriber);
        }
    }

    /** @param array<string, mixed> $options */
    public static function fromOptions(array $options): self
    {
        return new self($options);
    }

    /** Connect (and synchronize when configured). Called lazily by getConnection(). */
    public function initialize(): self
    {
        if ($this->initialized) {
            return $this;
        }
        $this->initialized = true;
        $this->connection ??= Connection::fromOptions($this->replication?->masterOptions() ?? $this->options);
        $this->installConnection($this->connection);
        $this->queryResultCache?->connect();
        if (! empty($this->options['dropSchema'])) {
            $this->dropDatabase();
        }
        if (! empty($this->options['synchronize'])) {
            if (self::isProduction()) {
                $this->warn('synchronize is enabled while APP_ENV is production: entity changes alter (and can drop) tables and columns. Use migrations in production (migration:generate / migration:run).');
            }
            $this->synchronize();
        }
        if (! empty($this->options['migrationsRun'])) {
            $this->runMigrations();
        }

        return $this;
    }

    /** Events, the logger and the `logging` callback on a connection of this DataSource (master or replica). */
    private function installConnection(Connection $connection): void
    {
        $this->broadcaster->install($connection);
        $max = $this->options['maxQueryExecutionTime'] ?? null;
        $connection->setOrmLogger($this->logger, $max === null ? null : (float) $max);
        if (($this->options['logging'] ?? null) instanceof Closure) {
            $connection->setLogger($this->options['logging']);
        }
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /** Use an existing PDO-backed connection instead of the options (tests, shared connections). */
    public function useConnection(Connection $connection): self
    {
        $this->connection = $connection;
        if ($this->initialized) {
            $this->installConnection($connection);
        }

        return $this;
    }

    public function getConnection(): Connection
    {
        $this->initialize();

        return $this->connection;
    }

    // ---- replication ----------------------------------------------------------------------------

    /** Is a `replication` option configured (TypeORM's driver.isReplicated)? */
    public function isReplicated(): bool
    {
        return $this->replication !== null;
    }

    /** Where SELECTs go by default: 'slave', or 'master' with replication.defaultMode = 'master' or without replication. */
    public function defaultReplicationModeForReads(): string
    {
        return $this->replication?->defaultMode ?? 'master';
    }

    /**
     * The connection of one slave (a random one when $index is null), opened on first use; the master when
     * there is no replication or no slave.
     */
    public function slaveConnection(?int $index = null): Connection
    {
        $master = $this->getConnection();

        return $this->replication?->slave($this->installConnection(...), $index) ?? $master;
    }

    /**
     * The connection a read runs on: the given QueryRunner's; else the one pinned by the load in progress;
     * else the master while it has a transaction open (PHP shares it: reads must see the transaction's writes),
     * without replication or with defaultMode 'master'; else a random slave.
     */
    public function readConnection(?QueryRunner $queryRunner = null): Connection
    {
        if ($queryRunner !== null && ! $queryRunner->isReleased()) {
            return $queryRunner->connection();
        }
        if ($this->pinnedRead !== null) {
            return $this->pinnedRead;
        }
        $master = $this->getConnection();
        if ($this->replication === null || $master->inTransaction() || $this->replication->defaultMode === 'master') {
            return $master;
        }

        return $this->slaveConnection();
    }

    /**
     * Run $fn with every read going to $connection (one find* and its relation / relation-id queries read from
     * the same node). Restored afterwards, also when $fn throws.
     *
     * @template T
     * @param Closure(): T $fn
     * @return T
     */
    public function readingFrom(Connection $connection, Closure $fn): mixed
    {
        if ($this->replication === null) {
            return $fn();
        }
        $previous = $this->pinnedRead;
        $this->pinnedRead = $connection;
        try {
            return $fn();
        } finally {
            $this->pinnedRead = $previous;
        }
    }

    /** @return array<string, mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function destroy(): void
    {
        if ($this->initialized) {
            $this->queryResultCache?->disconnect();
        }
        $this->connection?->setTransactionListener(null)->setQueryListeners(null, null)->setOrmLogger(null);
        $this->connection = null;
        $this->replication?->close();
        $this->pinnedRead = null;
        $this->runners = [];
        $this->defaultRunner = null;
        $this->initialized = false;
        $this->synchronized = false;
        $this->repositories = [];
    }

    // ---- entities -------------------------------------------------------------------------------

    /** @return list<class-string> */
    public function getEntities(): array
    {
        return $this->entities;
    }

    public function hasEntity(string $class): bool
    {
        return in_array($class, $this->entities, true);
    }

    /** Register an entity; when already initialized with `synchronize`, its table is created immediately. */
    public function addEntity(string $class): self
    {
        if ($this->hasEntity($class)) {
            return $this;
        }
        Mapping\Inheritance::register([$class]);
        EagerRelations::assertNoCycles(MetadataRegistry::get($class)); // validate early (TypeORM rejects circular eager relations at startup)
        $this->entities[] = $class;
        if ($this->synchronized) {
            $this->synchronizer()->synchronize([$class]);
        }

        return $this;
    }

    /** @param class-string $entity */
    public function getRepository(string $entity): Repository
    {
        if (! $this->hasEntity($entity)) {
            if (($this->options['autoLoadEntities'] ?? true) === false) {
                throw new OrmException("Entity \"$entity\" is not registered in the DataSource (entities option) and autoLoadEntities is off.");
            }
            $this->addEntity($entity);
        }

        return $this->repositories[$entity] ??= MetadataRegistry::get($entity)->tree !== null
            ? new TreeRepository($this, $entity)
            : new Repository($this, $entity);
    }

    /**
     * TypeORM's getTreeRepository(): the repository of a #[Tree] entity, with findTrees(), findDescendants(),
     * findAncestors(), … (getRepository() returns the same TreeRepository for tree entities).
     *
     * @param class-string $entity
     */
    public function getTreeRepository(string $entity): TreeRepository
    {
        $repository = $this->getRepository($entity);
        if (! $repository instanceof TreeRepository) {
            throw new OrmException("$entity is not a tree entity: add #[Tree('closure-table' | 'nested-set' | 'materialized-path' | 'adjacency-list')] and a #[TreeParent] property.");
        }

        return $repository;
    }

    public function getEntityManager(): EntityManager
    {
        return $this->manager;
    }

    /** TypeORM's dataSource.createEntityManager(): a manager bound to $queryRunner, or the DataSource's own. */
    public function createEntityManager(?QueryRunner $queryRunner = null): EntityManager
    {
        return $queryRunner?->manager ?? $this->manager;
    }

    /**
     * A QueryRunner for manual transaction control (see QueryRunner). With replication, 'slave' gives a runner
     * on a (random) slave: its queries and its manager's reads run there (TypeORM's createQueryRunner('slave')).
     *
     * @param 'master'|'slave' $mode
     */
    public function createQueryRunner(string $mode = 'master'): QueryRunner
    {
        return new QueryRunner($this, null, $mode);
    }

    // ---- subscribers ----------------------------------------------------------------------------

    /** Register an entity subscriber (same as `$dataSource->subscribers[] = $subscriber`, without duplicates). */
    public function addSubscriber(Events\EntitySubscriberInterface $subscriber): self
    {
        if (! in_array($subscriber, $this->subscribers, true)) {
            $this->subscribers[] = $subscriber;
        }

        return $this;
    }

    public function broadcaster(): Events\Broadcaster
    {
        return $this->broadcaster;
    }

    /**
     * The QueryRunner the current operation runs on: the innermost one with an open transaction, else the
     * DataSource's default runner (whose manager is the DataSource's manager). Events report it.
     */
    public function currentQueryRunner(): QueryRunner
    {
        for ($i = count($this->runners) - 1; $i >= 0; $i--) {
            $runner = $this->runners[$i]->get();
            if ($runner !== null && ! $runner->isReleased()) {
                return $runner;
            }
            array_splice($this->runners, $i, 1);
        }

        return $this->defaultRunner ??= new QueryRunner($this, $this->manager);
    }

    /** @internal QueryRunner bookkeeping: $runner opens a transaction. */
    public function enterQueryRunner(QueryRunner $runner): void
    {
        foreach ($this->runners as $ref) {
            if ($ref->get() === $runner) {
                return;
            }
        }
        $this->runners[] = \WeakReference::create($runner);
    }

    /** @internal QueryRunner bookkeeping: $runner has no open transaction any more. */
    public function leaveQueryRunner(QueryRunner $runner): void
    {
        $this->runners = array_values(array_filter($this->runners, static fn (\WeakReference $ref) => ($r = $ref->get()) !== null && $r !== $runner));
    }

    public function createQueryBuilder(?string $entityOrTable = null, ?string $alias = null): QueryBuilder
    {
        return new QueryBuilder($this, $entityOrTable, $alias);
    }

    public function state(): EntityState
    {
        return $this->state;
    }

    public function hydrator(): Hydrator
    {
        return $this->hydrator;
    }

    /** Writes entity graphs for save() / remove() / softRemove() / recover() (cascades). */
    public function persister(): Persistence\Persister
    {
        return $this->persister ??= new Persistence\Persister($this);
    }

    /**
     * Run $fn(EntityManager $transactional) in a transaction on a new QueryRunner (TypeORM's
     * dataSource.transaction()); nested calls become savepoints. Committed when $fn returns, rolled back
     * when it throws.
     *
     *   $ds->transaction(fn (EntityManager $tx) => $tx->save($order));
     *   $ds->transaction('SERIALIZABLE', fn (EntityManager $tx) => ...);
     *
     * @param Closure(EntityManager): mixed|string $isolationLevelOrFn
     */
    public function transaction(Closure|string $isolationLevelOrFn, ?Closure $fn = null): mixed
    {
        return $this->manager->transaction($isolationLevelOrFn, $fn);
    }

    /**
     * @internal Writes of save() / remove() / …: in their own transaction on a QueryRunner unless one is already
     * open (TypeORM), or without one with `transaction: false`.
     */
    public function runInTransaction(Closure $fn, bool $transaction = true): mixed
    {
        if (! $transaction || $this->getConnection()->inTransaction()) {
            return $fn();
        }

        return $this->manager->transaction(static fn (): mixed => $fn());
    }

    // ---- schema ---------------------------------------------------------------------------------

    /**
     * Bring the database in line with the registered entities (TypeORM's synchronize): missing tables are
     * created, columns added / changed / dropped, keys, indexes, foreign keys and checks replaced.
     */
    public function synchronize(bool $dropBeforeSync = false): void
    {
        if ($dropBeforeSync) {
            $this->dropDatabase();
        }
        $this->synchronizer()->synchronize($this->entities);
        $this->synchronized = true;
    }

    /** The statements synchronize() would run (TypeORM's `schema:log`); nothing is executed. @return list<string> */
    public function logSchema(): array
    {
        return $this->synchronizer()->log($this->entities);
    }

    /** Source of a migration class bringing the database in line with the entities; null when nothing differs. */
    public function generateMigration(string $class, ?string $comment = null): ?string
    {
        $synchronizer = $this->synchronizer();

        return (new MigrationGenerator($this->schema()))->generate($synchronizer->tables($this->entities), $class, $comment, $synchronizer->views($this->entities));
    }

    /** True when APP_ENV (environment, $_ENV or $_SERVER) is "production" / "prod". */
    public static function isProduction(): bool
    {
        $env = getenv('APP_ENV');
        $env = $env !== false ? $env : ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? '');

        return in_array(strtolower(trim((string) $env)), ['production', 'prod'], true);
    }

    private function warn(string $message): void
    {
        $logger = $this->options['logger'] ?? null;
        if ($logger instanceof Logging\Logger || is_string($logger)) {
            $this->logger->log('warn', $message);
        } elseif ($logger instanceof \Psr\Log\LoggerInterface) {
            $logger->warning($message);
        } elseif ($logger instanceof Closure) {
            $logger($message);
        } elseif (class_exists(\Nesto\Logger\Logger::class)) {
            (new \Nesto\Logger\Logger('ORM'))->warning($message);
        } else {
            error_log('[nesto/orm] ' . $message);
        }
    }

    /** Drop every entity table, join table and the migrations table. */
    public function dropDatabase(): void
    {
        $this->synchronizer()->drop($this->entities);
        $this->schema()->dropTableIfExists($this->migrationsTable());
        foreach (array_keys($this->schemaContributions()) as $table) {
            $this->schema()->dropTableIfExists($table);
        }
        $this->schema()->dropTableIfExists($this->metadataTableName());
        $this->synchronized = false;
    }

    public function schema(): Schema
    {
        return new Schema($this->getConnection(), $this->metadataTableName());
    }

    public function synchronizer(): SchemaSynchronizer
    {
        return new SchemaSynchronizer($this->schema(), $this->schemaContributions(), $this->viewExpression(...));
    }

    /**
     * The SQL of a #[ViewEntity]: its string expression, or what its callable ([Class::class, 'method'] or a
     * Closure, given this DataSource) returns — SQL or a QueryBuilder, whose parameters are inlined as literals.
     */
    public function viewExpression(EntityMetadata $meta): string
    {
        $expression = $meta->view?->expression ?? throw new OrmException("{$meta->class} is not a view entity.");
        if (is_string($expression)) {
            return trim($expression);
        }
        if (! is_callable($expression)) {
            throw new OrmException("The expression of view entity {$meta->class} must be SQL or a callable ([Class::class, 'staticMethod']).");
        }
        $result = $expression($this);
        if ($result instanceof QueryBuilder) {
            return trim(self::inlineParameters($result->getSql(), $result->getParameters(), $this->getConnection()));
        }
        if (! is_string($result)) {
            throw new OrmException("The expression callable of view entity {$meta->class} must return SQL or a QueryBuilder.");
        }

        return trim($result);
    }

    /** @param array<string, mixed> $params */
    private static function inlineParameters(string $sql, array $params, Connection $connection): string
    {
        return (string) preg_replace_callback('/(?<![:\w]):([A-Za-z_][A-Za-z0-9_]*)/', function (array $m) use ($params, $connection): string {
            if (! array_key_exists($m[1], $params)) {
                return $m[0];
            }
            $literal = function (mixed $value) use ($connection): string {
                $value = Mapping\TypeConverter::toParameter($value);

                return match (true) {
                    $value === null => 'NULL',
                    is_bool($value) => $connection->driver() === 'pgsql' ? ($value ? 'TRUE' : 'FALSE') : ($value ? '1' : '0'),
                    is_int($value), is_float($value) => (string) $value,
                    default => (string) $connection->pdo()->quote((string) $value),
                };
            };
            $value = $params[$m[1]];

            return is_array($value) ? implode(', ', array_map($literal, $value)) : $literal($value);
        }, $sql);
    }

    /** Tables that are not entities but belong to the schema: the query result cache table. @return array<string, \Nesto\Orm\Schema\Table> */
    private function schemaContributions(): array
    {
        if ($this->queryResultCache instanceof Cache\DbQueryResultCache) {
            return [$this->queryResultCache->tableName() => $this->queryResultCache->table()];
        }

        return [];
    }

    /**
     * The `cache` option as an array: alwaysEnabled, duration (ms, default 1000), ignoreErrors, …
     *
     * @return array<string, mixed>
     */
    public function cacheOptions(): array
    {
        $cache = $this->options['cache'] ?? null;

        return is_array($cache) ? $cache : [];
    }

    // ---- migrations -----------------------------------------------------------------------------

    public function migrator(): Migrator
    {
        return new Migrator(
            $this->getConnection(),
            $this->options['migrations'] ?? [],
            $this->migrationsTable(),
            (string) ($this->options['migrationsTransactionMode'] ?? 'all'),
            $this->metadataTableName(),
        );
    }

    /**
     * @param 'all'|'each'|'none'|null $transaction overrides the migrationsTransactionMode option
     * @return list<string> executed migration names
     */
    public function runMigrations(?string $transaction = null, bool $fake = false): array
    {
        return $this->migrator()->run($transaction, $fake);
    }

    /** @param 'all'|'each'|'none'|null $transaction */
    public function revertLastMigration(?string $transaction = null, bool $fake = false): ?string
    {
        return $this->migrator()->revert($transaction, $fake);
    }

    /** TypeORM's `metadataTableName` option: where view definitions are recorded (default "typeorm_metadata"). */
    public function metadataTableName(): string
    {
        return (string) ($this->options['metadataTableName'] ?? Schema::METADATA_TABLE);
    }

    private function migrationsTable(): string
    {
        return (string) ($this->options['migrationsTableName'] ?? $this->options['migrationsTable'] ?? 'migrations');
    }

    /** @return list<array{name: string, ran: bool, batch: ?int}> */
    public function showMigrations(): array
    {
        return $this->migrator()->status();
    }
}
