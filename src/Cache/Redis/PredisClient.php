<?php

declare(strict_types=1);

namespace Nesto\Cache\Redis;

/**
 * RedisClient over Predis (`composer require predis/predis`), used when the phpredis extension is not loaded.
 * Typed loosely: Predis is an optional dependency.
 */
final class PredisClient implements RedisClient
{
    /** @param object $client a \Predis\ClientInterface */
    public function __construct(private readonly object $client)
    {
    }

    public function client(): object
    {
        return $this->client;
    }

    public function get(string $key): ?string
    {
        $value = $this->client->get($key);

        return is_string($value) ? $value : null;
    }

    public function mget(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return array_map(fn ($v) => is_string($v) ? $v : null, array_values((array) $this->client->mget(array_values($keys))));
    }

    public function set(string $key, string $value, ?int $ttl = null): bool
    {
        $ttl !== null && $ttl > 0 ? $this->client->setex($key, $ttl, $value) : $this->client->set($key, $value);

        return true;
    }

    public function del(array $keys): int
    {
        return $keys === [] ? 0 : (int) $this->client->del(array_values($keys));
    }

    public function exists(string $key): bool
    {
        return (int) $this->client->exists($key) > 0;
    }

    public function scan(string $pattern): iterable
    {
        $cursor = '0';
        do {
            [$cursor, $keys] = $this->client->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 1000]);
            foreach ((array) $keys as $key) {
                yield (string) $key;
            }
        } while ((string) $cursor !== '0');
    }

    public function flushDb(): bool
    {
        $this->client->flushdb();

        return true;
    }
}
