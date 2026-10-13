<?php

declare(strict_types=1);

namespace Nesto\Cache;

use Nesto\Cache\Stores\ArrayStore;
use Nesto\Cache\Stores\DatabaseStore;
use Nesto\Cache\Stores\FileStore;
use Nesto\Cache\Stores\RedisStore;
use Nesto\Common\Contracts\DynamicModuleFactory;
use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;
use Nesto\Core\Container;
use Nesto\Core\Paths;
use Nesto\Database\DatabaseConfig;
use Nesto\Orm\Connection;
use Psr\SimpleCache\CacheInterface;

/**
 * Nesto's @nestjs/cache-manager. Provides a PSR-16 cache under the tokens
 * `Psr\SimpleCache\CacheInterface::class` and `'CACHE_MANAGER'`, plus CacheInterceptor.
 *
 *   #[Module(imports: [new CacheModule()])]                                     // config/cache.php + .env (CACHE_DRIVER…)
 *   #[Module(imports: [new CacheModule(ttl: 5, store: 'memory')])]              // in-process ArrayStore
 *   #[Module(imports: [new CacheModule(store: 'file', path: '/tmp/cache')])]   // FileStore
 *   #[Module(imports: [new CacheModule(store: 'redis')])]                     // RedisStore (REDIS_* from .env)
 *   #[Module(imports: [new CacheModule(store: 'database')])]                  // DatabaseStore on the ORM connection
 *   CacheModule::register(['ttl' => 60, 'store' => new Psr16Adapter($psr16)]);  // any PSR-16
 *   CacheModule::registerAsync([
 *       'imports' => [ConfigModule::class],
 *       'useFactory' => fn (ConfigService $c) => ['ttl' => $c->get('cache.ttl'), 'store' => 'file', 'path' => $c->get('cache.dir')],
 *       'inject' => [ConfigService::class],
 *   ]);
 *
 *   final class CatsService {
 *       public function __construct(private CacheInterface $cache) {}            // or #[Inject('CACHE_MANAGER')]
 *   }
 *
 * Without a `store`, the store comes from config/cache.php and .env (see CacheConfig): CACHE_DRIVER
 * (memory | file | database | redis; CACHE_STORE is accepted too), CACHE_TTL, CACHE_PREFIX, CACHE_PATH, REDIS_*.
 * Explicit arguments win over the configuration.
 *
 * Options: ttl (seconds, default 5, 0 = forever), store ('memory'|'array'|'file'|'database'|'redis'|CacheInterface),
 * path (file store directory, default sys_get_temp_dir()/nesto-cache), max (array store size cap), prefix
 * (redis / database keys), table + connection (database store), redis (connection options), global.
 * The same store backs ThrottlerModule (`storage: 'cache'`) and the ORM query cache (`cache: ['type' => 'psr16']`).
 */
final class CacheModule implements DynamicModuleFactory
{
    public const OPTIONS = 'CACHE_MODULE_OPTIONS';
    public const CACHE_MANAGER = 'CACHE_MANAGER';
    /** What registerAsync()'s factory returned, before normalize(). */
    private const FACTORY_OPTIONS = 'CACHE_MODULE_FACTORY_OPTIONS';

    public function __construct(
        private readonly ?int $ttl = null,
        private readonly string|CacheInterface|null $store = null,
        private readonly ?string $path = null,
        private readonly bool $global = true,
        private readonly ?int $max = null,
        private readonly ?string $prefix = null,
    ) {
    }

    /** @param array{ttl?: int, store?: string|CacheInterface, path?: ?string, global?: bool, max?: ?int, prefix?: string} $options */
    public static function register(array $options = []): DynamicModule
    {
        $global = (bool) ($options['global'] ?? $options['isGlobal'] ?? true);
        unset($options['global'], $options['isGlobal']);

        return self::build([(new Provider(self::OPTIONS))->useValue(self::resolveOptions($options))], [], $global);
    }

    /**
     * Options computed at runtime by a factory with injected dependencies
     * (without `inject` the factory's parameters are autowired by type).
     * @param array{useFactory: \Closure|array|string, inject?: list<string>, imports?: list<mixed>, global?: bool} $options
     */
    public static function registerAsync(array $options): DynamicModule
    {
        $factory = $options['useFactory'] ?? throw new \InvalidArgumentException('CacheModule::registerAsync() needs a "useFactory" callable.');

        return self::build(
            optionsProviders: [
                (new Provider(self::FACTORY_OPTIONS))->useFactory($factory, $options['inject'] ?? []),
                (new Provider(self::OPTIONS))->useFactory(fn (mixed $options) => self::normalize((array) $options), [self::FACTORY_OPTIONS]),
            ],
            imports: $options['imports'] ?? [],
            global: $options['global'] ?? $options['isGlobal'] ?? true,
        );
    }

    public function toDynamicModule(): DynamicModule
    {
        $options = self::resolveOptions(array_filter(
            ['ttl' => $this->ttl, 'store' => $this->store, 'path' => $this->path, 'max' => $this->max, 'prefix' => $this->prefix],
            fn ($v) => $v !== null,
        ));

        return self::build([(new Provider(self::OPTIONS))->useValue($options)], [], $this->global);
    }

    /**
     * Explicit options as given, or (without `store`) the configured store of config/cache.php / .env with the
     * explicit options on top.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function resolveOptions(array $options): array
    {
        if (! isset($options['store'])) {
            $options += CacheConfig::options(CacheConfig::load());
        }

        return self::normalize($options);
    }

    /** Build a store from an options array (what the module does internally). */
    public static function createStore(array $options, ?Container $container = null): CacheInterface
    {
        $options = self::normalize($options);
        $store = $options['store'];
        if ($store instanceof CacheInterface) {
            return $store;
        }

        return match ($store) {
            'memory', 'array' => new ArrayStore($options['max'], $options['ttl']),
            'file' => new FileStore(Paths::resolve($options['path'] ?? sys_get_temp_dir() . '/nesto-cache'), $options['ttl']),
            'redis' => new RedisStore(self::redisOptions($options), $options['prefix'], $options['ttl']),
            'database' => new DatabaseStore(
                $options['connection'] instanceof Connection ? $options['connection'] : static fn () => self::databaseConnection($container, $options['connection']),
                (string) ($options['table'] ?? 'cache'),
                $options['prefix'],
                $options['ttl'],
                (bool) ($options['autoCreate'] ?? true),
            ),
            default => throw new \InvalidArgumentException("CacheModule: unknown store \"$store\" (use memory, file, database, redis or a PSR-16 instance)."),
        };
    }

    /**
     * The connection of the database store: the named connection of config/database.php when `connection` is
     * set, else the application's ORM connection, else the Prisma client's, else the default connection of
     * config/database.php (CLI without an application).
     */
    public static function databaseConnection(?Container $container, mixed $name = null): Connection
    {
        if (! class_exists(Connection::class)) {
            throw new CacheException('The database cache store needs the Nesto ORM package.');
        }
        if (($name === null || $name === '') && $container !== null) {
            if (self::provides($container, Connection::class)) {
                return $container->get(Connection::class);
            }
            $prisma = 'Nesto\\Prisma\\Client\\PrismaClient';
            if (class_exists($prisma) && self::provides($container, $prisma)) {
                return $container->get($prisma)->connection();
            }
        }

        return Connection::fromOptions(DatabaseConfig::connectionOptions(DatabaseConfig::load(), is_string($name) && $name !== '' ? $name : null));
    }

    /** @param list<Provider> $optionsProviders */
    private static function build(array $optionsProviders, array $imports, bool $global): DynamicModule
    {
        return new DynamicModule(
            module: self::class,
            imports: $imports,
            providers: [
                ...$optionsProviders,
                (new Provider(CacheInterface::class))->useFactory(fn (array $options, Container $container) => self::createStore($options, $container), [self::OPTIONS, Container::class]),
                (new Provider(self::CACHE_MANAGER))->useExisting(CacheInterface::class),
                CacheInterceptor::class,
            ],
            exports: [self::OPTIONS, CacheInterface::class, self::CACHE_MANAGER, CacheInterceptor::class],
            global: $global,
        );
    }

    /** True when a module (not merely an existing class) provides $token. */
    private static function provides(Container $container, string $token): bool
    {
        if (isset($container->coreProviders()[$token])) {
            return true;
        }
        foreach ($container->modules() as $module) {
            if ($module->has($token)) {
                return true;
            }
        }

        return false;
    }

    /** Connection options of the redis store: `redis` => [...], or the url/host/port… keys, over the REDIS_* defaults. @return array<string, mixed> */
    private static function redisOptions(array $options): array
    {
        $defaults = CacheConfig::defaults()['stores']['redis'];
        unset($defaults['driver']);
        if (is_array($options['redis'] ?? null)) {
            return $options['redis'] + $defaults;
        }

        return array_intersect_key($options, $defaults) + $defaults;
    }

    /** @return array<string, mixed> ttl, store, path, max, prefix, connection + any store-specific keys */
    private static function normalize(array $options): array
    {
        return [
            'ttl' => max(0, (int) ($options['ttl'] ?? 5)),
            'store' => $options['store'] ?? 'memory',
            'path' => $options['path'] ?? null,
            'max' => isset($options['max']) ? (int) $options['max'] : null,
            'prefix' => (string) ($options['prefix'] ?? ''),
            'connection' => $options['connection'] ?? null,
        ] + $options;
    }
}
