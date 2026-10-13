<?php

declare(strict_types=1);

namespace Nesto\Orm\Cache;

use Nesto\Orm\QueryRunner;
use Psr\SimpleCache\CacheInterface;

/**
 * Query results in a PSR-16 cache — TypeORM's "redis" / "ioredis" cache types map here (give the Redis-backed
 * PSR-16 store as `cache.options`), as does `type: 'psr16'` (with OrmModule, the application's
 * Psr\SimpleCache\CacheInterface provider, e.g. CacheModule's store, is used when no instance is given).
 *
 * Keys are "<prefix><sha1>" of the cache id (or of the query when there is none), with the entry's duration
 * as TTL. clear() clears the whole PSR-16 store, like TypeORM's Redis cache flushes its database.
 */
final class Psr16QueryResultCache implements QueryResultCache
{
    public function __construct(private readonly CacheInterface $cache, private readonly string $prefix = 'nesto-orm-query-cache.') {}

    public function store(): CacheInterface
    {
        return $this->cache;
    }

    public function connect(): void {}

    public function disconnect(): void {}

    public function synchronize(?QueryRunner $queryRunner = null): void {}

    public function getFromCache(array $options, ?QueryRunner $queryRunner = null): ?array
    {
        $key = $this->keyFor($options['identifier'] ?? null, $options['query'] ?? null);
        if ($key === null) {
            return null;
        }
        $entry = $this->cache->get($key);

        return is_array($entry) && isset($entry['time'], $entry['duration'], $entry['result']) ? $entry : null;
    }

    public function storeInCache(array $options, ?array $savedCache, ?QueryRunner $queryRunner = null): void
    {
        $key = $this->keyFor($options['identifier'] ?? null, $options['query'] ?? null);
        if ($key === null) {
            return;
        }
        $entry = [
            'identifier' => $options['identifier'] ?? null,
            'query' => $options['query'] ?? null,
            'time' => (int) $options['time'],
            'duration' => (int) $options['duration'],
            'result' => (string) $options['result'],
        ];
        $this->cache->set($key, $entry, max(1, (int) ceil($entry['duration'] / 1000)));
    }

    public function isExpired(array $savedCache): bool
    {
        return (int) $savedCache['time'] + (int) $savedCache['duration'] < (int) floor(microtime(true) * 1000);
    }

    public function clear(?QueryRunner $queryRunner = null): void
    {
        $this->cache->clear();
    }

    public function remove(array $identifiers, ?QueryRunner $queryRunner = null): void
    {
        $keys = array_values(array_filter(array_map(fn (string $id) => $this->keyFor($id, null), $identifiers)));
        if ($keys !== []) {
            $this->cache->deleteMultiple($keys);
        }
    }

    private function keyFor(?string $identifier, ?string $query): ?string
    {
        return match (true) {
            $identifier !== null => $this->prefix . sha1('id:' . $identifier),
            $query !== null => $this->prefix . sha1('query:' . $query),
            default => null,
        };
    }
}
