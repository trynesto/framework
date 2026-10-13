<?php

declare(strict_types=1);

namespace Nesto\Cache;

use Nesto\Config\ConfigFile;

/**
 * The cache configuration: config/cache.php on top of these env-driven defaults.
 *
 *   CACHE_DRIVER   memory | file | database | redis   (CACHE_STORE is read too; without either: memory)
 *   CACHE_TTL      default TTL in seconds (0 = forever; default 5 without a config file)
 *   CACHE_PREFIX   key prefix of the shared stores (redis, database)
 *   CACHE_PATH     file store directory (default storage/framework/cache)
 *   CACHE_TABLE    database store table (default cache)
 *   REDIS_URL, or REDIS_HOST / REDIS_PORT / REDIS_USERNAME / REDIS_PASSWORD / REDIS_DB, REDIS_CLIENT (phpredis|predis)
 */
final class CacheConfig
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'default' => env('CACHE_DRIVER', env('CACHE_STORE', 'memory')),
            'ttl' => env('CACHE_TTL', 5),
            'prefix' => env('CACHE_PREFIX', ''),
            'stores' => [
                'memory' => ['driver' => 'memory', 'max' => null],
                'file' => ['driver' => 'file', 'path' => env('CACHE_PATH', 'storage/framework/cache')],
                'database' => ['driver' => 'database', 'table' => env('CACHE_TABLE', 'cache'), 'connection' => env('CACHE_DB_CONNECTION')],
                'redis' => [
                    'driver' => 'redis',
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', 6379),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', 0),
                    'client' => env('REDIS_CLIENT', 'auto'),
                    'timeout' => 2.0,
                ],
            ],
        ];
    }

    /** Defaults < config/cache.php. @return array<string, mixed> */
    public static function load(): array
    {
        return ConfigFile::load('cache', self::defaults());
    }

    /**
     * CacheModule options for one store of the configuration (the default one when $store is null):
     * ['ttl', 'store' => driver, 'prefix', ...the store's own keys].
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function options(array $config, ?string $store = null): array
    {
        $name = (string) ($store ?? $config['default'] ?? 'memory');
        $name = $name === 'array' ? 'memory' : $name;
        $spec = $config['stores'][$name] ?? null;
        if (! is_array($spec)) {
            if (! in_array($name, ['memory', 'file', 'database', 'redis'], true)) {
                throw new \InvalidArgumentException("Cache: unknown store \"$name\" (CACHE_DRIVER: memory, file, database or redis, or a store of config/cache.php).");
            }
            $spec = self::defaults()['stores'][$name];
        }
        $driver = (string) ($spec['driver'] ?? $name);
        unset($spec['driver']);

        return ['ttl' => (int) ($spec['ttl'] ?? $config['ttl'] ?? 5), 'store' => $driver === 'array' ? 'memory' : $driver, 'prefix' => (string) ($spec['prefix'] ?? $config['prefix'] ?? ''), 'name' => $name] + $spec;
    }

    /** One line for `php nesto info`: "redis (127.0.0.1:6379/0), ttl 3600s, prefix app_cache_". @param array<string, mixed> $config */
    public static function describe(array $config): string
    {
        $options = self::options($config);
        $detail = match ($options['store']) {
            'file' => ' (' . ($options['path'] ?? 'storage/framework/cache') . ')',
            'database' => ' (table ' . ($options['table'] ?? 'cache') . (($options['connection'] ?? null) ? ', connection ' . $options['connection'] : '') . ')',
            'redis' => ' (' . self::redisTarget($options) . ')',
            default => '',
        };

        return $options['store'] . $detail . ', ttl ' . ($options['ttl'] === 0 ? 'forever' : $options['ttl'] . 's') . ($options['prefix'] !== '' ? ', prefix ' . $options['prefix'] : '');
    }

    /** @param array<string, mixed> $options */
    private static function redisTarget(array $options): string
    {
        $parsed = \Nesto\Cache\Redis\RedisConnector::parse($options);

        return ($parsed['host'] ?? '127.0.0.1') . ':' . ($parsed['port'] ?? 6379) . '/' . ($parsed['database'] ?? 0);
    }
}
