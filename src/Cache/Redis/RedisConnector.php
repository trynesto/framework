<?php

declare(strict_types=1);

namespace Nesto\Cache\Redis;

use Nesto\Cache\CacheException;

/**
 * Opens a Redis connection from the cache config (REDIS_URL, or REDIS_HOST / REDIS_PORT / REDIS_PASSWORD /
 * REDIS_DB): the phpredis extension when it is loaded, else Predis when installed.
 *
 *   RedisConnector::connect(['url' => 'redis://:secret@127.0.0.1:6379/2']);
 *   RedisConnector::connect(['host' => 'redis', 'port' => 6379, 'password' => null, 'database' => 0, 'client' => 'predis']);
 *
 * `rediss://` (or 'scheme' => 'tls') connects over TLS.
 */
final class RedisConnector
{
    /** @param array<string, mixed> $config url, host, port, username, password, database, timeout, scheme, client (phpredis|predis) */
    public static function connect(array $config): RedisClient
    {
        $config = self::parse($config);
        $client = strtolower((string) ($config['client'] ?? ''));
        if ($client === '' || $client === 'auto') {
            $client = extension_loaded('redis') && class_exists(\Redis::class) ? 'phpredis' : (class_exists('Predis\\Client') ? 'predis' : '');
        }

        return match ($client) {
            'phpredis' => self::phpredis($config),
            'predis' => self::predis($config),
            default => throw new CacheException('The redis cache store needs the phpredis extension (pecl install redis) or Predis (composer require predis/predis).'),
        };
    }

    /**
     * The URL (when given) split into host, port, username, password, database, scheme; the other keys kept.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function parse(array $config): array
    {
        $url = $config['url'] ?? null;
        unset($config['url']);
        if (! is_string($url) || $url === '') {
            return $config;
        }
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            throw new CacheException('Invalid REDIS_URL "' . preg_replace('#//[^@]*@#', '//***@', $url) . '".');
        }
        $parsed = ['scheme' => in_array($parts['scheme'] ?? 'redis', ['rediss', 'tls'], true) ? 'tls' : 'tcp', 'host' => rawurldecode($parts['host'])];
        if (isset($parts['port'])) {
            $parsed['port'] = (int) $parts['port'];
        }
        if (isset($parts['user']) && $parts['user'] !== '') {
            $parsed['username'] = rawurldecode($parts['user']);
        }
        if (isset($parts['pass'])) {
            $parsed['password'] = rawurldecode($parts['pass']);
        }
        $db = trim($parts['path'] ?? '', '/');
        if ($db !== '' && ctype_digit($db)) {
            $parsed['database'] = (int) $db;
        }
        parse_str($parts['query'] ?? '', $query);
        if (isset($query['database']) && is_numeric($query['database'])) {
            $parsed['database'] = (int) $query['database'];
        }

        return $parsed + $config;
    }

    /** @param array<string, mixed> $config */
    private static function phpredis(array $config): PhpRedisClient
    {
        $redis = new \Redis();
        $host = (string) ($config['host'] ?? '127.0.0.1');
        if (($config['scheme'] ?? 'tcp') === 'tls' && ! str_contains($host, '://')) {
            $host = 'tls://' . $host;
        }
        $port = (int) ($config['port'] ?? 6379);
        $timeout = (float) ($config['timeout'] ?? 2.0);
        try {
            if (! $redis->connect($host, $port, $timeout)) {
                throw new CacheException("Cannot connect to Redis at $host:$port.");
            }
            $password = $config['password'] ?? null;
            if ($password !== null && $password !== '') {
                $username = $config['username'] ?? null;
                $redis->auth($username !== null && $username !== '' ? [(string) $username, (string) $password] : (string) $password);
            }
            $database = (int) ($config['database'] ?? 0);
            if ($database !== 0) {
                $redis->select($database);
            }
        } catch (\RedisException $e) {
            throw new CacheException("Cannot connect to Redis at $host:$port: " . $e->getMessage(), 0, $e);
        }

        return new PhpRedisClient($redis);
    }

    /** @param array<string, mixed> $config */
    private static function predis(array $config): PredisClient
    {
        $class = 'Predis\\Client';
        if (! class_exists($class)) {
            throw new CacheException('REDIS_CLIENT=predis needs predis/predis (composer require predis/predis).');
        }
        $parameters = array_filter([
            'scheme' => $config['scheme'] ?? 'tcp',
            'host' => $config['host'] ?? '127.0.0.1',
            'port' => (int) ($config['port'] ?? 6379),
            'username' => $config['username'] ?? null,
            'password' => $config['password'] ?? null,
            'database' => (int) ($config['database'] ?? 0),
            'timeout' => (float) ($config['timeout'] ?? 2.0),
        ], fn ($v) => $v !== null && $v !== '');

        return new PredisClient(new $class($parameters));
    }
}
