<?php

declare(strict_types=1);

namespace Nesto\Throttler\Storage;

use Nesto\Throttler\ThrottlerStorage;
use Psr\SimpleCache\CacheInterface;

/**
 * Hit counters in any PSR-16 cache — plug Redis or Memcached in through an external PSR-16
 * package, or reuse the CacheModule store:
 *
 *   ThrottlerModule::forRoot(['storage' => new Psr16Storage($psr16Redis)]);
 *
 * The read-modify-write is not atomic: under heavy concurrency a few hits above the limit may slip
 * through. Use a native Redis INCR-based storage when exactness matters.
 */
final class Psr16Storage implements ThrottlerStorage
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'throttle_',
    ) {
    }

    public function increment(string $key, int $ttl): array
    {
        $cacheKey = $this->prefix . sha1($key);
        $now = microtime(true);
        $record = $this->cache->get($cacheKey);
        if (! is_array($record) || ($record['expiresAt'] ?? 0) <= $now) {
            $record = ['hits' => 0, 'expiresAt' => $now + max(1, $ttl)];
        }
        $record['hits']++;
        $remaining = max(1, (int) ceil($record['expiresAt'] - $now));
        $this->cache->set($cacheKey, $record, $remaining);

        return ['totalHits' => (int) $record['hits'], 'timeToExpire' => $remaining];
    }
}
