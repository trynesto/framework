<?php

declare(strict_types=1);

namespace Nesto\Orm\Cache;

use Closure;
use Nesto\Orm\DataSource;
use Nesto\Orm\OrmException;
use Psr\SimpleCache\CacheInterface;

/**
 * The query result cache of the `cache` DataSource option (TypeORM's QueryResultCacheFactory):
 *
 *   'cache' => true                                                    // database table "query-result-cache"
 *   'cache' => ['type' => 'database', 'tableName' => 'orm_cache', 'duration' => 30_000]
 *   'cache' => ['type' => 'redis', 'options' => $psr16RedisStore]      // 'ioredis', 'ioredis/cluster', 'psr16' alike
 *   'cache' => ['provider' => fn (DataSource $ds) => new MyCache($ds)]  // any QueryResultCache
 *   'cache' => ['alwaysEnabled' => true, 'ignoreErrors' => true, ...]  // cache every query / fall back to the database
 */
final class QueryResultCacheFactory
{
    public const PSR16_TYPES = ['psr16', 'redis', 'ioredis', 'ioredis/cluster'];

    public static function create(DataSource $dataSource, mixed $option): ?QueryResultCache
    {
        if ($option === null || $option === false) {
            return null;
        }
        $options = is_array($option) ? $option : [];
        if (isset($options['provider'])) {
            $cache = ($options['provider'])($dataSource);
            if (! $cache instanceof QueryResultCache) {
                throw new OrmException('cache.provider must return a ' . QueryResultCache::class . '.');
            }

            return $cache;
        }
        $type = $options['type'] ?? 'database';
        if ($type === 'database') {
            return new DbQueryResultCache($dataSource, (string) ($options['tableName'] ?? DbQueryResultCache::DEFAULT_TABLE));
        }
        if (in_array($type, self::PSR16_TYPES, true)) {
            $store = $options['options'] ?? null;
            if ($store instanceof Closure) {
                $store = $store($dataSource);
            }
            if (! $store instanceof CacheInterface) {
                throw new OrmException("cache.type \"$type\" needs a Psr\\SimpleCache\\CacheInterface as cache.options (with OrmModule the application's CacheInterface provider is used when there is one).");
            }

            return new Psr16QueryResultCache($store, (string) ($options['prefix'] ?? 'nesto-orm-query-cache.'));
        }
        throw new OrmException("Unknown cache type \"$type\" (expected database, psr16, redis, ioredis or a provider).");
    }
}
