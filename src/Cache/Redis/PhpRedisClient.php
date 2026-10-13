<?php

declare(strict_types=1);

namespace Nesto\Cache\Redis;

/** RedisClient over the phpredis extension (`\Redis`). */
final class PhpRedisClient implements RedisClient
{
    public function __construct(private readonly \Redis $redis)
    {
    }

    public function redis(): \Redis
    {
        return $this->redis;
    }

    public function get(string $key): ?string
    {
        $value = $this->redis->get($key);

        return is_string($value) ? $value : null;
    }

    public function mget(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $values = $this->redis->mGet(array_values($keys));

        return array_map(fn ($v) => is_string($v) ? $v : null, is_array($values) ? array_values($values) : array_fill(0, count($keys), null));
    }

    public function set(string $key, string $value, ?int $ttl = null): bool
    {
        $result = $ttl !== null && $ttl > 0 ? $this->redis->setex($key, $ttl, $value) : $this->redis->set($key, $value);

        return $result !== false;
    }

    public function del(array $keys): int
    {
        return $keys === [] ? 0 : (int) $this->redis->del(array_values($keys));
    }

    public function exists(string $key): bool
    {
        return (int) $this->redis->exists($key) > 0;
    }

    public function scan(string $pattern): iterable
    {
        $this->redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
        $iterator = null;
        do {
            $keys = $this->redis->scan($iterator, $pattern, 1000);
            foreach ($keys ?: [] as $key) {
                yield $key;
            }
        } while ($iterator > 0);
    }

    public function flushDb(): bool
    {
        return $this->redis->flushDB() !== false;
    }
}
