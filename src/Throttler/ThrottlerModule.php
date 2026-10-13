<?php

declare(strict_types=1);

namespace Nesto\Throttler;

use Nesto\Common\Attributes\Inject;
use Nesto\Common\Contracts\DynamicModuleFactory;
use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;
use Nesto\Core\Constants;
use Nesto\Core\Container;
use Nesto\Throttler\Storage\FileStorage;
use Nesto\Throttler\Storage\MemoryStorage;

/**
 * Nesto's @nestjs/throttler. Provides ThrottlerStorage, ThrottlerGuard and a global filter that keeps
 * the rate-limit headers on 429 responses. Like Nest, the guard is NOT applied automatically:
 *
 *   #[Module(
 *       imports: [new ThrottlerModule(ttl: 60, limit: 10, storage: 'file')],
 *       providers: [new Provider(Constants::APP_GUARD, useClass: ThrottlerGuard::class)],   // everywhere
 *   )]
 *   final class AppModule {}
 *
 *   ThrottlerModule::forRoot(['ttl' => 60, 'limit' => 100, 'storage' => new Psr16Storage($redis), 'ignoreUserAgents' => ['/googlebot/i']]);
 *   ThrottlerModule::forRootAsync([
 *       'useFactory' => fn (ConfigService $c) => ['ttl' => 60, 'limit' => (int) $c->get('THROTTLE_LIMIT'), 'storage' => $c->get('THROTTLE_STORAGE')],
 *       'inject' => [ConfigService::class],
 *   ]);
 *
 * `storage`: null|'memory' → MemoryStorage, 'file' or 'file:/dir' → FileStorage, 'cache' → the CacheModule store
 * (Redis, database… from CACHE_DRIVER, shared by every server), a class name resolved
 * through the container, or a ThrottlerStorage instance (forRoot only).
 */
final class ThrottlerModule implements DynamicModuleFactory
{
    public const OPTIONS = 'THROTTLER_MODULE_OPTIONS';

    /** @param list<string> $ignoreUserAgents regexes (or plain substrings) matched against User-Agent */
    public function __construct(
        private readonly int $ttl = 60,
        private readonly int $limit = 10,
        private readonly string|ThrottlerStorage|null $storage = null,
        private readonly array $ignoreUserAgents = [],
        private readonly bool $global = true,
    ) {
    }

    /** @param array{ttl?: int, limit?: int, storage?: string|ThrottlerStorage|null, ignoreUserAgents?: list<string>, global?: bool} $options */
    public static function forRoot(array $options = []): DynamicModule
    {
        return (new self(
            ttl: $options['ttl'] ?? 60,
            limit: $options['limit'] ?? 10,
            storage: $options['storage'] ?? null,
            ignoreUserAgents: $options['ignoreUserAgents'] ?? [],
            global: $options['global'] ?? $options['isGlobal'] ?? true,
        ))->toDynamicModule();
    }

    /**
     * Options (ttl, limit, storage, ignoreUserAgents) produced by a factory with injected dependencies.
     *
     * @param array{useFactory: \Closure|array|string, inject?: list<string>, imports?: list<mixed>, global?: bool} $options
     */
    public static function forRootAsync(array $options): DynamicModule
    {
        $factory = $options['useFactory'] ?? throw new \InvalidArgumentException('ThrottlerModule::forRootAsync() needs a "useFactory" option.');

        return self::build(
            (new Provider(self::OPTIONS))->useFactory($factory, $options['inject'] ?? []),
            (bool) ($options['global'] ?? $options['isGlobal'] ?? true),
            $options['imports'] ?? [],
        );
    }

    public function toDynamicModule(): DynamicModule
    {
        return self::build((new Provider(self::OPTIONS))->useValue([
            'ttl' => $this->ttl,
            'limit' => $this->limit,
            'ignoreUserAgents' => $this->ignoreUserAgents,
            'storage' => $this->storage,
        ]), $this->global);
    }

    private static function build(Provider $optionsProvider, bool $global, array $imports = []): DynamicModule
    {
        return new DynamicModule(
            module: self::class,
            imports: $imports,
            providers: [
                $optionsProvider,
                (new Provider(ThrottlerStorage::class))->useFactory(
                    fn (#[Inject(self::OPTIONS)] array $options, Container $container) => self::createStorage($options['storage'] ?? null, $container)
                ),
                ThrottlerGuard::class,
                new Provider(Constants::APP_FILTER, useClass: ThrottlerExceptionFilter::class),
            ],
            exports: [self::OPTIONS, ThrottlerStorage::class, ThrottlerGuard::class],
            global: $global,
        );
    }

    public static function createStorage(string|ThrottlerStorage|null $storage, ?Container $container = null): ThrottlerStorage
    {
        if ($storage instanceof ThrottlerStorage) {
            return $storage;
        }
        if ($storage === null || $storage === 'memory') {
            return new MemoryStorage;
        }
        if ($storage === 'file') {
            return new FileStorage;
        }
        if ($storage === 'cache') {
            // the application's PSR-16 store (CacheModule: CACHE_DRIVER=redis / database / file…)
            if ($container === null || ! interface_exists(\Psr\SimpleCache\CacheInterface::class) || ! $container->has(\Psr\SimpleCache\CacheInterface::class)) {
                throw new \InvalidArgumentException('ThrottlerModule: storage "cache" needs CacheModule (Psr\\SimpleCache\\CacheInterface) in the application.');
            }

            return new Storage\Psr16Storage($container->get(\Psr\SimpleCache\CacheInterface::class));
        }
        if (str_starts_with($storage, 'file:')) {
            return new FileStorage(substr($storage, 5));
        }
        if (class_exists($storage) || interface_exists($storage)) {
            $instance = $container?->resolve($storage, null, strict: false) ?? new $storage;
            if ($instance instanceof ThrottlerStorage) {
                return $instance;
            }
        }

        throw new \InvalidArgumentException("ThrottlerModule: unknown storage \"$storage\".");
    }
}
