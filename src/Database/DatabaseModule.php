<?php

declare(strict_types=1);

namespace Nesto\Database;

use Nesto\Common\Contracts\DynamicModuleFactory;
use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;
use Nesto\Orm\OrmModule;
use Nesto\Prisma\PrismaModule;

/**
 * The database of the application, chosen in .env — no code change to switch:
 *
 *   ORM_DRIVER=orm      → OrmModule::forRootAsync() with the DB_* connection, the entities of nesto.config.php,
 *                          DB_SYNCHRONIZE, DB_LOGGING, DB_CACHE (the default)
 *   ORM_DRIVER=prisma   → PrismaModule (prisma/schema.prisma, DATABASE_URL)
 *   ORM_DRIVER=none     → nothing
 *
 *   #[Module(imports: [new ConfigModule(isGlobal: true), new LoggerModule(), new DatabaseModule(), new CacheModule()])]
 *   #[Module(imports: [new DatabaseModule(driver: 'orm', connection: 'pgsql')])]          // explicit wins over .env
 *   DatabaseModule::forRoot(['synchronize' => true, 'orm' => ['morphMap' => [...]]]);    // overrides config/database.php
 *
 * The settings live in config/database.php (see DatabaseConfig for every key); OrmModule and PrismaModule stay
 * usable directly. Failed queries go to the application log (LOG_CHANNEL) through the framework Logger.
 */
final class DatabaseModule implements DynamicModuleFactory
{
    /** DI token of the effective database configuration (array). */
    public const CONFIG = 'DATABASE_MODULE_CONFIG';

    /**
     * @param ?string              $driver     overrides ORM_DRIVER (orm, prisma, none)
     * @param ?string              $connection overrides DB_CONNECTION (a key of config/database.php `connections`)
     * @param array<string, mixed> $options    merged over config/database.php
     */
    public function __construct(
        private readonly ?string $driver = null,
        private readonly ?string $connection = null,
        private readonly array $options = [],
    ) {
    }

    /** @param array<string, mixed> $options keys of config/database.php (driver, default, connections, synchronize, orm, prisma…) */
    public static function forRoot(array $options = []): DynamicModule
    {
        return (new self(options: $options))->toDynamicModule();
    }

    /** The effective configuration: defaults < config/database.php < this module's arguments. @return array<string, mixed> */
    public function config(): array
    {
        return DatabaseConfig::load(array_filter(['driver' => $this->driver, 'default' => $this->connection], fn ($v) => $v !== null) + $this->options);
    }

    public function toDynamicModule(): DynamicModule
    {
        $config = $this->config();
        $imports = match (DatabaseConfig::driver($config)) {
            // options are computed when the DataSource is first needed: the entity scan does not slow down commands that never touch it
            'orm' => [OrmModule::forRootAsync(['useFactory' => static fn () => DatabaseConfig::ormOptions($config)])],
            'prisma' => [PrismaModule::forRoot(DatabaseConfig::prismaOptions($config))],
            'none' => [],
        };

        return new DynamicModule(
            module: self::class,
            imports: $imports,
            providers: [(new Provider(self::CONFIG))->useValue($config)],
            exports: [self::CONFIG],
            global: true,
        );
    }
}
