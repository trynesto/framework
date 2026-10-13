<?php

declare(strict_types=1);

namespace Nesto\Database;

use Nesto\Config\ConfigFile;
use Nesto\Config\ConfigModule;
use Nesto\Core\Paths;
use Nesto\Logger\Logger;

/**
 * The database configuration: config/database.php on top of these env-driven defaults.
 *
 *   ORM_DRIVER       orm (default: the built-in ORM, OrmModule) | prisma (PrismaModule) | none
 *   DB_CONNECTION    sqlite | mysql | mariadb | pgsql (a key of `connections`)
 *   DB_URL           a URL (pgsql://user:pass@host:5432/db) instead of the separate keys
 *   DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_CHARSET
 *   DB_SYNCHRONIZE   true: align the tables with the entities on boot (development only)
 *   DB_LOGGING       false (errors only) | true (queries + errors) | all | none | query,error,…
 *   DB_CACHE         false | true / database (query-result-cache table) | cache (the CacheModule store)
 *   DB_MIGRATIONS_RUN  true: run pending migrations on boot
 *   DATABASE_URL     Prisma's datasource URL (default: the schema's env("DATABASE_URL"))
 *
 * Entities: `entities` of config/database.php, else nesto.config.php's `entities` glob (src/**\/Entities); the
 * ORM also registers every entity listed in an OrmModule::forFeature() (autoLoadEntities).
 */
final class DatabaseConfig
{
    public const DRIVERS = ['orm', 'prisma', 'none'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        $server = fn (string $driver, int $port, string $username) => [
            'driver' => $driver,
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', $port),
            'database' => env('DB_DATABASE', 'nesto'),
            'username' => env('DB_USERNAME', $username),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET'),
        ];

        return [
            'driver' => env('ORM_DRIVER', 'orm'),
            'default' => env('DB_CONNECTION', 'sqlite'),
            'connections' => [
                'sqlite' => ['driver' => 'sqlite', 'url' => env('DB_URL'), 'database' => env('DB_DATABASE', 'database/database.sqlite')],
                'mysql' => $server('mysql', 3306, 'root'),
                'mariadb' => $server('mariadb', 3306, 'root'),
                'pgsql' => $server('pgsql', 5432, 'postgres'),
            ],
            'entities' => null,
            'synchronize' => env('DB_SYNCHRONIZE', false),
            'logging' => env('DB_LOGGING', false),
            'cache' => env('DB_CACHE', false),
            'migrations_run' => env('DB_MIGRATIONS_RUN', false),
            'replication' => [],
            'orm' => [],
            'prisma' => ['url' => env('DATABASE_URL'), 'schema' => null, 'log' => []],
        ];
    }

    /**
     * Defaults < config/database.php < $overrides.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function load(array $overrides = []): array
    {
        return ConfigModule::merge(ConfigFile::load('database', self::defaults()), $overrides);
    }

    /** orm | prisma | none. @param array<string, mixed> $config */
    public static function driver(array $config): string
    {
        $driver = strtolower(trim((string) ($config['driver'] ?? 'orm')));
        $driver = match ($driver) {
            '', 'typeorm', 'nesto' => 'orm',
            'false', 'null', 'off' => 'none',
            default => $driver,
        };
        if (! in_array($driver, self::DRIVERS, true)) {
            throw new \InvalidArgumentException("ORM_DRIVER must be orm, prisma or none (got \"$driver\").");
        }

        return $driver;
    }

    /**
     * Connection options for Nesto\Orm\Connection::fromOptions() / OrmModule: the connection named $name (the
     * default one when null). A relative SQLite path is resolved from the project root and its folder created.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function connectionOptions(array $config, ?string $name = null): array
    {
        $name ??= (string) ($config['default'] ?? 'sqlite');
        $spec = $config['connections'][$name] ?? null;
        if (! is_array($spec)) {
            if (! in_array($name, ['sqlite', 'mysql', 'mariadb', 'pgsql', 'postgres'], true)) {
                throw new \InvalidArgumentException("DB_CONNECTION \"$name\" is not a connection of config/database.php (sqlite, mysql, mariadb, pgsql).");
            }
            $spec = self::defaults()['connections'][$name === 'postgres' ? 'pgsql' : $name];
        }
        $options = [
            'type' => (string) ($spec['driver'] ?? $name),
            'url' => self::blankToNull($spec['url'] ?? null),
            'host' => self::blankToNull($spec['host'] ?? null),
            'port' => isset($spec['port']) && $spec['port'] !== '' ? (int) $spec['port'] : null,
            'database' => self::blankToNull($spec['database'] ?? null),
            'username' => self::blankToNull($spec['username'] ?? null),
            'password' => self::blankToNull($spec['password'] ?? null),
            'charset' => self::blankToNull($spec['charset'] ?? null),
        ];
        if (isset($spec['options']) && is_array($spec['options'])) {
            $options['options'] = $spec['options'];
        }
        if ($options['url'] === null && in_array(strtolower($options['type']), ['sqlite', 'sqlite3'], true)) {
            $options['database'] = self::sqlitePath($options['database']);
        }

        return array_filter($options, fn ($v) => $v !== null);
    }

    /**
     * OrmModule options: the connection, entities, synchronize, logging (to the application log), cache,
     * replication, plus anything under `orm` (morphMap, subscribers, types, maxQueryExecutionTime…).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function ormOptions(array $config): array
    {
        $options = self::connectionOptions($config) + [
            'entities' => self::entities($config),
            'synchronize' => ConfigFile::bool($config['synchronize'] ?? false),
            'logging' => self::logging($config['logging'] ?? false),
            'logger' => new Logger('Orm'),
            'migrationsRun' => ConfigFile::bool($config['migrations_run'] ?? false),
        ];
        $cache = self::cache($config['cache'] ?? false);
        if ($cache !== false) {
            $options['cache'] = $cache;
        }
        if (! empty($config['replication'])) {
            $options['replication'] = $config['replication'];
        }

        return array_replace($options, (array) ($config['orm'] ?? []));
    }

    /**
     * PrismaModule options: DATABASE_URL (else the schema's own env("DATABASE_URL")), the schema path, `log`,
     * and the application logger for failed queries.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function prismaOptions(array $config): array
    {
        $prisma = (array) ($config['prisma'] ?? []);
        $options = array_filter([
            'datasourceUrl' => self::blankToNull($prisma['url'] ?? null),
            'schema' => self::blankToNull($prisma['schema'] ?? null),
            'log' => array_values(array_filter((array) ($prisma['log'] ?? []))),
        ], fn ($v) => $v !== null && $v !== []);
        $options['logger'] = new Logger('Prisma');

        return $options + array_diff_key($prisma, array_flip(['url', 'schema', 'log']));
    }

    /**
     * The entity classes: `entities` of config/database.php (class names and/or globs), else the `entities` glob of
     * nesto.config.php (default src/**\/Entities).
     *
     * @param array<string, mixed> $config
     * @return list<class-string>
     */
    public static function entities(array $config): array
    {
        $patterns = $config['entities'] ?? null;
        if ($patterns === null || $patterns === '' || $patterns === []) {
            $patterns = self::projectConfig()['entities'] ?? 'src/**/Entities';
        }

        return EntityScanner::scan($patterns, Paths::base());
    }

    /** One line for `php nesto info`. @param array<string, mixed> $config */
    public static function describe(array $config): string
    {
        $driver = self::driver($config);
        if ($driver === 'none') {
            return 'none';
        }
        if ($driver === 'prisma') {
            $url = self::blankToNull($config['prisma']['url'] ?? null);

            return 'prisma (' . ($url !== null ? self::mask($url) : 'DATABASE_URL of prisma/schema.prisma') . ')';
        }
        $name = (string) ($config['default'] ?? 'sqlite');

        return "orm, connection $name (" . self::target(self::connectionOptions($config)) . ')';
    }

    /** "pgsql postgres@127.0.0.1:5432/app" / "sqlite database/database.sqlite". @param array<string, mixed> $options */
    public static function target(array $options): string
    {
        if (isset($options['url'])) {
            return self::mask((string) $options['url']);
        }
        $type = (string) ($options['type'] ?? 'sqlite');
        if (in_array($type, ['sqlite', 'sqlite3'], true)) {
            $path = (string) ($options['database'] ?? ':memory:');
            $base = rtrim(Paths::base(), '/') . '/';

            return 'sqlite ' . (str_starts_with($path, $base) ? substr($path, strlen($base)) : $path);
        }

        return sprintf(
            '%s %s%s:%s/%s',
            $type,
            isset($options['username']) ? $options['username'] . '@' : '',
            $options['host'] ?? '127.0.0.1',
            $options['port'] ?? \Nesto\Orm\Connection::defaultPort($type),
            $options['database'] ?? '',
        );
    }

    /** @return bool|string|list<string> the ORM `logging` option: errors are always logged unless "none" */
    public static function logging(mixed $value): bool|string|array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if ($value === true) {
            return true;
        }
        $text = strtolower(trim((string) ($value === false || $value === null ? '' : $value)));

        return match ($text) {
            '', 'false', '0', 'off', 'no', 'error', 'errors' => ['error', 'warn'],
            'none' => false,
            'true', '1', 'on', 'yes' => true,
            'all' => 'all',
            default => ConfigFile::list($text),
        };
    }

    /** @return false|true|array<string, mixed> the ORM `cache` option */
    public static function cache(mixed $value): bool|array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === true) {
            return true;
        }
        $text = strtolower(trim((string) ($value === false || $value === null ? '' : $value)));

        return match ($text) {
            '', 'false', '0', 'off', 'no', 'none' => false,
            'true', '1', 'on', 'yes', 'database' => true,
            'cache', 'psr16', 'redis' => ['type' => 'psr16'],
            default => throw new \InvalidArgumentException("DB_CACHE must be false, database or cache (got \"$text\")."),
        };
    }

    /** nesto.config.php of the project (entities, migrations…). @return array<string, mixed> */
    public static function projectConfig(): array
    {
        $file = Paths::base('nesto.config.php');
        if (! is_file($file)) {
            return [];
        }
        $loaded = (static fn (string $__file) => require $__file)($file);

        return is_array($loaded) ? $loaded : [];
    }

    private static function sqlitePath(?string $database): string
    {
        if ($database === null || $database === '' || $database === ':memory:' || str_starts_with($database, 'file:')) {
            return $database ?: ':memory:';
        }
        $path = Paths::resolve($database);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $path;
    }

    private static function mask(string $url): string
    {
        return (string) preg_replace('#//([^:@/]*):[^@/]*@#', '//$1:***@', $url);
    }

    private static function blankToNull(mixed $value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
