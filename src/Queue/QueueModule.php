<?php

declare(strict_types=1);

namespace Nesto\Queue;

use Nesto\Common\Attributes\Inject;
use Nesto\Common\Contracts\DynamicModuleFactory;
use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;
use Nesto\Core\Container;
use Nesto\Queue\Drivers\DatabaseDriver;
use Nesto\Queue\Drivers\FileDriver;
use Nesto\Queue\Drivers\SyncDriver;
use Psr\Log\LoggerInterface;

/**
 * Nesto's @nestjs/bull (simplified, no Redis dependency).
 *
 *   #[Module(imports: [
 *       new QueueModule(driver: 'file', path: __DIR__ . '/../storage/queue'),   // 'sync' (default) | 'file' | 'database'
 *       new QueueFeatureModule('mail', 'video'),                                // or QueueModule::registerQueue('mail')
 *   ])]
 *   final class AppModule {}
 *
 *   final class MailService {
 *       public function __construct(#[InjectQueue('mail')] private Queue $mail) {}
 *       public function welcome(User $u): void { $this->mail->add('welcome', ['id' => $u->id], ['attempts' => 5]); }
 *   }
 *
 *   #[Processor('mail')]
 *   final class MailProcessor { #[Process('welcome')] public function welcome(Job $job): void {} }
 *
 *   // consume: $app->get(Worker::class)->work(['mail'])   → `php nesto queue:work mail`
 *
 * forRoot() also accepts 'driver' => QueueDriver instance and 'pdo' => PDO (database driver).
 * forRootAsync() computes the same options from other providers (BullModule.forRootAsync()):
 *
 *   QueueModule::forRootAsync([
 *       'useFactory' => fn (ConfigService $c) => ['driver' => $c->get('queue.driver'), 'path' => $c->get('queue.path')],
 *       'inject' => [ConfigService::class],
 *   ]);
 *   // inside #[Module]: [QueueModule::class, 'forRootAsync', [['useFactory' => [QueueConfig::class, 'options'], 'inject' => [ConfigService::class]]]]
 * `connection` is a PDO DSN; without it the database driver resolves PDO::class from the container.
 */
final class QueueModule implements DynamicModuleFactory
{
    public const OPTIONS = 'QUEUE_MODULE_OPTIONS';

    public function __construct(
        private readonly string $driver = 'sync',
        private readonly ?string $path = null,
        private readonly ?string $connection = null,
        private readonly bool $global = true,
    ) {
    }

    /** @param array{driver?: string|QueueDriver, path?: ?string, connection?: ?string, pdo?: ?\PDO, table?: string, global?: bool} $options */
    public static function forRoot(array $options = []): DynamicModule
    {
        return self::build($options + ['driver' => 'sync'], $options['global'] ?? $options['isGlobal'] ?? true);
    }

    /**
     * Options produced by a factory with injected dependencies.
     *
     * @param array{useFactory: \Closure|array|string, inject?: list<string>, imports?: list<mixed>, global?: bool} $options
     */
    public static function forRootAsync(array $options): DynamicModule
    {
        $factory = $options['useFactory'] ?? throw new QueueException('QueueModule::forRootAsync() needs a "useFactory" option.');

        return self::buildWith(
            (new Provider(self::OPTIONS))->useFactory($factory, $options['inject'] ?? []),
            (bool) ($options['global'] ?? $options['isGlobal'] ?? true),
            $options['imports'] ?? [],
        );
    }

    /** Register injectable Queue providers, one per name (token: QueueModule::queueToken($name)). */
    public static function registerQueue(string ...$names): DynamicModule
    {
        $providers = [];
        $exports = [];
        foreach ($names as $name) {
            $token = self::queueToken($name);
            $providers[] = (new Provider($token))->useFactory(fn (QueueManager $manager) => $manager->get($name), [QueueManager::class]);
            $exports[] = $token;
        }

        return new DynamicModule(module: self::class, providers: $providers, exports: $exports);
    }

    /** DI token of a named queue: "NestoQueue<mail>". */
    public static function queueToken(string $name): string
    {
        return "NestoQueue<$name>";
    }

    public function toDynamicModule(): DynamicModule
    {
        return self::build(['driver' => $this->driver, 'path' => $this->path, 'connection' => $this->connection], $this->global);
    }

    /** Build a driver from an options array (what the module does internally). */
    public static function createDriver(array $options, ProcessorRegistry $registry, ?Container $container = null, ?LoggerInterface $logger = null): QueueDriver
    {
        $driver = $options['driver'] ?? 'sync';
        if ($driver instanceof QueueDriver) {
            return $driver;
        }

        return match ($driver) {
            'sync' => new SyncDriver($registry, $logger),
            'file' => new FileDriver($options['path'] ?? null),
            'database' => new DatabaseDriver(
                $options['pdo'] ?? $options['connection'] ?? self::pdoFromContainer($container),
                $options['table'] ?? 'jobs',
            ),
            default => throw new QueueException("QueueModule: unknown driver \"$driver\" (use sync, file, database or a QueueDriver instance)."),
        };
    }

    private static function build(array $options, bool $global): DynamicModule
    {
        return self::buildWith((new Provider(self::OPTIONS))->useValue($options), $global);
    }

    private static function buildWith(Provider $optionsProvider, bool $global, array $imports = []): DynamicModule
    {
        return new DynamicModule(
            module: self::class,
            imports: $imports,
            providers: [
                $optionsProvider,
                ProcessorRegistry::class,
                ProcessorExplorer::class,
                (new Provider(QueueDriver::class))->useFactory(
                    fn (#[Inject(self::OPTIONS)] array $options, ProcessorRegistry $registry, Container $container, ?LoggerInterface $logger = null) => self::createDriver($options + ['driver' => 'sync'], $registry, $container, $logger)
                ),
                QueueManager::class,
                Worker::class,
            ],
            exports: [self::OPTIONS, ProcessorRegistry::class, ProcessorExplorer::class, QueueDriver::class, QueueManager::class, Worker::class],
            global: $global,
        );
    }

    private static function pdoFromContainer(?Container $container): \PDO
    {
        if ($container !== null && $container->has(\PDO::class)) {
            $pdo = $container->get(\PDO::class);
            if ($pdo instanceof \PDO) {
                return $pdo;
            }
        }

        throw new QueueException('QueueModule: the database driver needs a "connection" DSN, a "pdo" instance, or a PDO::class provider.');
    }
}
