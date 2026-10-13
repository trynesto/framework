<?php

declare(strict_types=1);

namespace Nesto\Cache\Redis;

/**
 * The few Redis commands the cache needs, over phpredis (PhpRedisClient) or Predis (PredisClient).
 * Values are strings: RedisStore serializes them.
 */
interface RedisClient
{
    public function get(string $key): ?string;

    /**
     * @param list<string> $keys
     * @return list<?string> in the order of $keys
     */
    public function mget(array $keys): array;

    /** SET, or SETEX when $ttl is a positive number of seconds. */
    public function set(string $key, string $value, ?int $ttl = null): bool;

    /** @param list<string> $keys @return int number of keys removed */
    public function del(array $keys): int;

    public function exists(string $key): bool;

    /** Every key matching a glob pattern (SCAN, never KEYS). @return iterable<string> */
    public function scan(string $pattern): iterable;

    /** FLUSHDB: empties the selected database. */
    public function flushDb(): bool;
}
