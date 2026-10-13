<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Nesto\Common\Contracts\DynamicModuleFactory;
use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;
use Nesto\Core\Container;
use Nesto\Core\DiscoveryService;

/**
 * Global module providing DataSource, EntityManager and Connection (Nest's TypeOrmModule).
 *
 * Inside a #[Module] attribute (constant expressions only):
 *   #[Module(imports: [new OrmModule(type: 'sqlite', database: ':memory:', synchronize: true, entities: [Cat::class])])]
 *
 * Polymorphic relations store a short alias instead of the class name with `morphMap`:
 *   new OrmModule(type: 'sqlite', database: ':memory:', morphMap: ['post' => Post::class, 'video' => Video::class])
 *
 * `synchronize: true` diffs the entities against the database on boot and applies it (TypeORM semantics:
 * columns are altered and dropped) — a warning is logged when APP_ENV is production; use migrations there
 * (`php nesto migration:generate`, `migration:run`, `migrationsTransactionMode: 'all'|'each'|'none'`).
 *
 * At runtime:
 *   OrmModule::forRoot(['type' => 'mysql', 'host' => ..., 'database' => ..., 'username' => ..., 'password' => ..., 'entities' => [...]])
 *   OrmModule::forRootAsync(['useFactory' => fn (ConfigService $c) => $c->get('db'), 'inject' => [ConfigService::class]])
 *
 * Entity subscribers: `subscribers: [AuditSubscriber::class]` (created with dependency injection) or any
 * provider marked #[EventSubscriber]; both are registered on the DataSource when the modules initialize.
 *
 * Several connections (@nestjs/typeorm's named data sources): give every extra one a `name` and pass it on:
 *   OrmModule::forRoot(['name' => 'analytics', 'type' => 'postgres', ...])
 *   OrmModule::forFeature([Event::class], 'analytics')
 *   #[InjectDataSource('analytics')] DataSource $ds, #[InjectEntityManager('analytics')] EntityManager $em,
 *   #[InjectRepository(Event::class, 'analytics')] Repository $events
 *
 * Per feature module:
 *   #[Module(imports: [new OrmFeatureModule(Cat::class)])]   or   OrmModule::forFeature([Cat::class])
 *   then `#[InjectRepository(Cat::class)] Repository $cats` in a provider's constructor.
 *   Custom repositories (#[CustomRepository(Cat::class)] class CatRepository extends Repository) are listed the
 *   same way and injected by class — #[InjectRepository(Cat::class)] then returns the custom repository too.
 */
final class OrmModule implements DynamicModuleFactory
{
    public const OPTIONS = 'NestoOrm:OPTIONS';
    public const DEFAULT_DATA_SOURCE = 'default';

    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param list<class-string>                   $entities
     * @param list<class-string|object|string>     $migrations classes, instances or directories
     * @param array<int, mixed>                    $driverOptions PDO driver options
     * @param array<string, class-string>          $morphMap alias => entity class, stored in polymorphic type columns
     * @param list<class-string>                   $subscribers EntitySubscriberInterface classes (dependency-injected)
     * @param ?string                              $name data source name (null: the default one)
     * @param bool|string|list<string>             $logging true | 'all' | ['query', 'error', 'schema', 'warn', 'info', 'log', 'migration']
     * @param string|Logging\Logger|\Psr\Log\LoggerInterface|null $logger 'advanced-console' | 'simple-console' | 'file' | 'debug', or an
     *                                             instance (`new FileLogger(...)` works in an attribute)
     * @param ?string                              $relationLoadStrategy default of the find option: 'query' | 'join'
     * @param array<string, class-string>          $types custom column types (Types\TypeRegistry)
     * @param bool|array<string, mixed>            $cache query result cache (see Cache\QueryResultCacheFactory)
     * @param array<string, mixed>                 $replication ['master' => [...], 'slaves' => [[...], ...]] (see Replication)
     */
    public function __construct(
        string $type = 'sqlite',
        ?string $database = null,
        ?string $host = null,
        ?int $port = null,
        ?string $username = null,
        ?string $password = null,
        array $entities = [],
        bool $synchronize = false,
        bool|string|array $logging = false,
        array $migrations = [],
        bool $autoLoadEntities = true,
        ?string $url = null,
        ?string $charset = null,
        array $driverOptions = [],
        array $morphMap = [],
        ?string $migrationsTableName = null,
        string $migrationsTransactionMode = 'all',
        bool $migrationsRun = false,
        bool $dropSchema = false,
        array $subscribers = [],
        ?string $name = null,
        string|Logging\Logger|\Psr\Log\LoggerInterface|null $logger = null,
        ?int $maxQueryExecutionTime = null,
        bool|array $cache = false,
        array $replication = [],
        ?string $metadataTableName = null,
        ?string $relationLoadStrategy = null,
        array $types = [],
    ) {
        $this->options = [
            'type' => $type,
            'database' => $database,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'entities' => $entities,
            'synchronize' => $synchronize,
            'logging' => $logging,
            'migrations' => $migrations,
            'autoLoadEntities' => $autoLoadEntities,
            'url' => $url,
            'charset' => $charset,
            'options' => $driverOptions,
            'morphMap' => $morphMap,
            'migrationsTableName' => $migrationsTableName,
            'migrationsTransactionMode' => $migrationsTransactionMode,
            'migrationsRun' => $migrationsRun,
            'dropSchema' => $dropSchema,
            'subscribers' => $subscribers,
            'name' => $name,
            'logger' => $logger,
            'maxQueryExecutionTime' => $maxQueryExecutionTime,
            'cache' => $cache,
            'replication' => $replication,
            'metadataTableName' => $metadataTableName,
            'relationLoadStrategy' => $relationLoadStrategy,
            'types' => $types,
        ];
    }

    public function toDynamicModule(): DynamicModule
    {
        return self::forRoot($this->options);
    }

    /**
     * @param array<string, mixed> $options every DataSource option (see DataSource; the constructor's keys, with
     *                                      `options` for the PDO driver options), `name` for an extra data source
     */
    public static function forRoot(array $options): DynamicModule
    {
        $name = self::nameOf($options['name'] ?? null);

        return self::build((new Provider(self::optionsToken($name)))->useValue($options), [], $name);
    }

    /**
     * @param array{useFactory: \Closure|array|string, inject?: list<string>, imports?: list<mixed>, name?: string} $options
     *   The factory returns the options array; `inject` tokens are resolved (from `imports` / globals)
     *   and passed to it in order, e.g. ['useFactory' => fn (ConfigService $c) => $c->get('db'), 'inject' => [ConfigService::class]].
     *   `name` names the data source (like @nestjs/typeorm's forRootAsync({ name })).
     */
    public static function forRootAsync(array $options): DynamicModule
    {
        $factory = $options['useFactory'] ?? throw new OrmException('forRootAsync() needs a "useFactory" option.');
        $name = self::nameOf($options['name'] ?? null);

        return self::build((new Provider(self::optionsToken($name)))->useFactory($factory, $options['inject'] ?? []), $options['imports'] ?? [], $name);
    }

    /**
     * @param list<class-string> $entities entities and/or custom repository classes
     * @param ?string            $dataSource the data source name (null: the default one)
     */
    public static function forFeature(array $entities, ?string $dataSource = null): DynamicModule
    {
        return OrmFeatureModule::forFeature($entities, $dataSource);
    }

    /** DI token of the Repository for an entity: "NestoRepository<App\Entities\Cat>" ("NestoRepository<analytics:…>" when named). */
    public static function repositoryToken(string $entity, ?string $dataSource = null): string
    {
        $name = self::nameOf($dataSource);

        return 'NestoRepository<' . ($name === null ? '' : $name . ':') . $entity . '>';
    }

    /** @nestjs/typeorm's getRepositoryToken(). */
    public static function getRepositoryToken(string $entity, ?string $dataSource = null): string
    {
        return self::repositoryToken($entity, $dataSource);
    }

    /** @nestjs/typeorm's getDataSourceToken(): DataSource::class for the default data source. */
    public static function getDataSourceToken(?string $dataSource = null): string
    {
        $name = self::nameOf($dataSource);

        return $name === null ? DataSource::class : 'NestoDataSource<' . $name . '>';
    }

    /** @nestjs/typeorm's getEntityManagerToken(): EntityManager::class for the default data source. */
    public static function getEntityManagerToken(?string $dataSource = null): string
    {
        $name = self::nameOf($dataSource);

        return $name === null ? EntityManager::class : 'NestoEntityManager<' . $name . '>';
    }

    public static function getConnectionToken(?string $dataSource = null): string
    {
        $name = self::nameOf($dataSource);

        return $name === null ? Connection::class : 'NestoConnection<' . $name . '>';
    }

    /**
     * A PSR-16 query cache (`cache.type` psr16 / redis / ioredis) without a store in `cache.options` uses the
     * application's Psr\SimpleCache\CacheInterface provider (CacheModule's store, a Redis adapter…).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function withCacheStore(array $options, Container $container): array
    {
        $cache = $options['cache'] ?? null;
        if (! is_array($cache) || isset($cache['options']) || isset($cache['provider'])
            || ! in_array($cache['type'] ?? 'database', Cache\QueryResultCacheFactory::PSR16_TYPES, true)) {
            return $options;
        }
        if (! $container->has(\Psr\SimpleCache\CacheInterface::class)) {
            throw new OrmException('cache.type "' . $cache['type'] . '" needs a PSR-16 store: pass one as cache.options or import CacheModule (which provides Psr\SimpleCache\CacheInterface).');
        }
        $options['cache']['options'] = $container->get(\Psr\SimpleCache\CacheInterface::class);

        return $options;
    }

    private static function optionsToken(?string $name): string
    {
        return $name === null ? self::OPTIONS : self::OPTIONS . '<' . $name . '>';
    }

    /** null for the default data source ('default' or no name). */
    private static function nameOf(?string $name): ?string
    {
        return $name === null || $name === '' || $name === self::DEFAULT_DATA_SOURCE ? null : $name;
    }

    private static function build(Provider $optionsProvider, array $imports, ?string $name): DynamicModule
    {
        $dataSource = self::getDataSourceToken($name);
        $manager = self::getEntityManagerToken($name);
        $connection = self::getConnectionToken($name);
        $explorer = 'NestoOrm:SUBSCRIBERS<' . ($name ?? self::DEFAULT_DATA_SOURCE) . '>';

        return new DynamicModule(
            module: self::class,
            imports: $imports,
            providers: [
                $optionsProvider,
                (new Provider($dataSource))->useFactory(
                    // subscribers listed in the options are created with DI by the SubscriberExplorer instead
                    fn (array $options, Container $container) => (new DataSource(['subscribers' => []] + self::withCacheStore($options, $container)))->initialize(),
                    inject: [self::optionsToken($name), Container::class],
                ),
                (new Provider($manager))->useFactory(fn (DataSource $ds) => $ds->getEntityManager(), inject: [$dataSource]),
                (new Provider($connection))->useFactory(fn (DataSource $ds) => $ds->getConnection(), inject: [$dataSource]),
                (new Provider($explorer))->useFactory(
                    fn (DataSource $ds, array $options, DiscoveryService $discovery, Container $container) => new SubscriberExplorer($ds, $name, $options['subscribers'] ?? [], $discovery, $container),
                    inject: [$dataSource, self::optionsToken($name), DiscoveryService::class, Container::class],
                ),
                TransactionalInterceptor::class,
            ],
            exports: [$dataSource, $manager, $connection, TransactionalInterceptor::class],
            global: true,
        );
    }
}
