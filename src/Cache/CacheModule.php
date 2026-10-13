<?php

declare(strict_types=1);

namespace Nesto\Cache;

use Nesto\Cache\Stores\ArrayStore;
use Nesto\Cache\Stores\FileStore;
use Nesto\Common\Contracts\DynamicModuleFactory;
use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;
use Psr\SimpleCache\CacheInterface;

/**
 * Nesto's @nestjs/cache-manager. Provides a PSR-16 cache under the tokens
 * `Psr\SimpleCache\CacheInterface::class` and `'CACHE_MANAGER'`, plus CacheInterceptor.
 *
 *   #[Module(imports: [new CacheModule(ttl: 5, store: 'memory')])]              // in-process ArrayStore
 *   #[Module(imports: [new CacheModule(store: 'file', path: '/tmp/cache')])]   // FileStore
 *   CacheModule::register(['ttl' => 60, 'store' => new Psr16Adapter($redisPsr16)]);   // any PSR-16 (Redis…)
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
 * Options: ttl (seconds, default 5, 0 = forever), store ('memory'|'array'|'file'|CacheInterface),
 * path (file store directory, default sys_get_temp_dir()/nesto-cache), max (array store size cap), global.
 */
final class CacheModule implements DynamicModuleFactory
{
    public const OPTIONS = 'CACHE_MODULE_OPTIONS';
    public const CACHE_MANAGER = 'CACHE_MANAGER';
    /** What registerAsync()'s factory returned, before normalize(). */
    private const FACTORY_OPTIONS = 'CACHE_MODULE_FACTORY_OPTIONS';

    public function __construct(
        private readonly int $ttl = 5,
        private readonly string|CacheInterface $store = 'memory',
        private readonly ?string $path = null,
        private readonly bool $global = true,
        private readonly ?int $max = null,
    ) {
    }

    /** @param array{ttl?: int, store?: string|CacheInterface, path?: ?string, global?: bool, max?: ?int} $options */
    public static function register(array $options = []): DynamicModule
    {
        return (new self(
            ttl: $options['ttl'] ?? 5,
            store: $options['store'] ?? 'memory',
            path: $options['path'] ?? null,
            global: $options['global'] ?? $options['isGlobal'] ?? true,
            max: $options['max'] ?? null,
        ))->toDynamicModule();
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
        $options = self::normalize(['ttl' => $this->ttl, 'store' => $this->store, 'path' => $this->path, 'max' => $this->max]);

        return self::build([(new Provider(self::OPTIONS))->useValue($options)], [], $this->global);
    }

    /** Build a store from an options array (what the module does internally). */
    public static function createStore(array $options): CacheInterface
    {
        $options = self::normalize($options);
        $store = $options['store'];
        if ($store instanceof CacheInterface) {
            return $store;
        }

        return match ($store) {
            'memory', 'array' => new ArrayStore($options['max'], $options['ttl']),
            'file' => new FileStore($options['path'] ?? sys_get_temp_dir() . '/nesto-cache', $options['ttl']),
            default => throw new \InvalidArgumentException("CacheModule: unknown store \"$store\" (use memory, file or a PSR-16 instance)."),
        };
    }

    /** @param list<Provider> $optionsProviders */
    private static function build(array $optionsProviders, array $imports, bool $global): DynamicModule
    {
        return new DynamicModule(
            module: self::class,
            imports: $imports,
            providers: [
                ...$optionsProviders,
                (new Provider(CacheInterface::class))->useFactory(fn (array $options) => self::createStore($options), [self::OPTIONS]),
                (new Provider(self::CACHE_MANAGER))->useExisting(CacheInterface::class),
                CacheInterceptor::class,
            ],
            exports: [self::OPTIONS, CacheInterface::class, self::CACHE_MANAGER, CacheInterceptor::class],
            global: $global,
        );
    }

    /** @return array{ttl: int, store: string|CacheInterface, path: ?string, max: ?int} */
    private static function normalize(array $options): array
    {
        return [
            'ttl' => max(0, (int) ($options['ttl'] ?? 5)),
            'store' => $options['store'] ?? 'memory',
            'path' => $options['path'] ?? null,
            'max' => isset($options['max']) ? (int) $options['max'] : null,
        ];
    }
}
