<?php

declare(strict_types=1);

namespace Nesto\Orm\Cache;

use Nesto\Orm\QueryRunner;

/**
 * TypeORM's QueryResultCache: where cached query results live (`$dataSource->queryResultCache`).
 * Implementations: DbQueryResultCache (the "query-result-cache" table), Psr16QueryResultCache (any PSR-16
 * store: Redis, Nesto's CacheModule…); a custom one through the `cache.provider` option.
 *
 * A cache entry: ['identifier' => ?string, 'query' => string, 'time' => ms timestamp, 'duration' => ms,
 * 'result' => JSON of the raw rows].
 *
 *   $dataSource->queryResultCache->remove(['users_admins']);   // by the ids given to ->cache('users_admins', …)
 *   $dataSource->queryResultCache->clear();
 */
interface QueryResultCache
{
    public function connect(): void;

    public function disconnect(): void;

    /** Create what the cache needs (the cache table) when it does not exist yet. */
    public function synchronize(?QueryRunner $queryRunner = null): void;

    /**
     * The saved entry for an identifier (when given) or a query, expired or not; null when none.
     *
     * @param array{identifier?: ?string, query?: ?string, duration?: int} $options
     * @return array{identifier: ?string, query: ?string, time: int, duration: int, result: string}|null
     */
    public function getFromCache(array $options, ?QueryRunner $queryRunner = null): ?array;

    /**
     * Save an entry; $savedCache is what getFromCache() returned (an existing entry is updated).
     *
     * @param array{identifier: ?string, query: string, time: int, duration: int, result: string} $options
     * @param array<string, mixed>|null $savedCache
     */
    public function storeInCache(array $options, ?array $savedCache, ?QueryRunner $queryRunner = null): void;

    /** @param array{time: int|string, duration: int|string} $savedCache */
    public function isExpired(array $savedCache): bool;

    /** Remove every cached result. */
    public function clear(?QueryRunner $queryRunner = null): void;

    /** @param list<string> $identifiers cache ids given to ->cache('id', …) / the `cache.id` find option */
    public function remove(array $identifiers, ?QueryRunner $queryRunner = null): void;
}
