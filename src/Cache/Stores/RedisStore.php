<?php

declare(strict_types=1);

namespace Nesto\Cache\Stores;

use Nesto\Cache\CacheException;
use Nesto\Cache\Redis\PhpRedisClient;
use Nesto\Cache\Redis\PredisClient;
use Nesto\Cache\Redis\RedisClient;
use Nesto\Cache\Redis\RedisConnector;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 store on Redis (CACHE_DRIVER=redis): phpredis when the extension is loaded, else Predis.
 * Values are serialize()d, keys carry the prefix (CACHE_PREFIX), TTLs are native Redis expirations.
 *
 *   new RedisStore(['host' => '127.0.0.1', 'port' => 6379, 'database' => 0], prefix: 'app_cache:', defaultTtl: 3600);
 *   new RedisStore(['url' => 'redis://:secret@redis:6379/1']);
 *   new RedisStore($phpRedis);            // an existing \Redis, Predis client or RedisClient
 *
 * The connection opens on first use. clear() deletes only the prefixed keys (SCAN + DEL); with an empty prefix
 * it flushes the selected database (FLUSHDB), so give a shared Redis a prefix.
 */
final class RedisStore implements CacheInterface
{
    use Psr16Support;

    private ?RedisClient $client = null;
    /** @var array<string, mixed>|\Closure|null */
    private array|\Closure|null $connect = null;

    /** @param RedisClient|\Redis|object|array<string, mixed>|\Closure(): (RedisClient|\Redis|object) $client */
    public function __construct(mixed $client, private readonly string $prefix = '', ?int $defaultTtl = null)
    {
        $this->defaultTtl = $defaultTtl;
        if (is_array($client) || $client instanceof \Closure) {
            $this->connect = $client;
        } else {
            $this->client = self::wrap($client);
        }
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function client(): RedisClient
    {
        if ($this->client === null) {
            $connect = $this->connect;
            $this->client = is_array($connect) ? RedisConnector::connect($connect) : self::wrap(($connect)());
        }

        return $this->client;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        $raw = $this->client()->get($this->prefix . $key);

        return $raw === null ? $default : $this->decode($raw, $default);
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $list = [];
        foreach ($keys as $key) {
            $this->validateKey((string) $key);
            $list[] = (string) $key;
        }
        $values = $this->client()->mget(array_map(fn (string $k) => $this->prefix . $k, $list));
        $out = [];
        foreach ($list as $i => $key) {
            $out[$key] = ($values[$i] ?? null) === null ? $default : $this->decode($values[$i], $default);
        }

        return $out;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        $seconds = $this->ttlSeconds($ttl) ?? $this->defaultTtl;

        return $this->client()->set($this->prefix . $key, serialize($value), $seconds === null || $seconds === 0 ? null : $seconds);
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        $this->client()->del([$this->prefix . $key]);

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $list = [];
        foreach ($keys as $key) {
            $this->validateKey((string) $key);
            $list[] = $this->prefix . $key;
        }
        $this->client()->del($list);

        return true;
    }

    public function clear(): bool
    {
        $client = $this->client();
        if ($this->prefix === '') {
            return $client->flushDb();
        }
        $batch = [];
        foreach ($client->scan(self::escapeGlob($this->prefix) . '*') as $key) {
            $batch[] = $key;
            if (count($batch) >= 500) {
                $client->del($batch);
                $batch = [];
            }
        }
        $client->del($batch);

        return true;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);

        return $this->client()->exists($this->prefix . $key);
    }

    private function decode(string $raw, mixed $default): mixed
    {
        if ($raw === 'b:0;') {
            return false;
        }
        $value = @unserialize($raw);

        return $value === false ? $default : $value;
    }

    private static function wrap(mixed $client): RedisClient
    {
        return match (true) {
            $client instanceof RedisClient => $client,
            $client instanceof \Redis => new PhpRedisClient($client),
            is_object($client) && is_a($client, 'Predis\\ClientInterface') => new PredisClient($client),
            default => throw new CacheException('RedisStore needs a \\Redis, a Predis client, a ' . RedisClient::class . ' or connection options.'),
        };
    }

    private static function escapeGlob(string $text): string
    {
        return addcslashes($text, '*?[]\\');
    }
}
