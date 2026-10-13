<?php

declare(strict_types=1);

namespace Nesto\Throttler;

/**
 * Hit counter behind ThrottlerGuard. Implementations: MemoryStorage (per process), FileStorage
 * (shared between PHP-FPM workers on one host), Psr16Storage (Redis/Memcached through any PSR-16 cache).
 */
interface ThrottlerStorage
{
    /**
     * Increment the counter for $key, starting a new $ttl-second window when none is active.
     * @return array{totalHits: int, timeToExpire: int} hits in the current window and seconds until it resets
     */
    public function increment(string $key, int $ttl): array;
}
