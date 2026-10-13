<?php

declare(strict_types=1);

namespace Nesto\Throttler\Storage;

use Nesto\Throttler\ThrottlerStorage;

/**
 * In-memory hit counters (one PHP process). The default store; fine for tests, CLI and
 * long-running servers, useless across PHP-FPM workers — use FileStorage or Psr16Storage there.
 */
final class MemoryStorage implements ThrottlerStorage
{
    /** @var array<string, array{0: int, 1: float}> key => [hits, expiresAt] */
    private array $records = [];

    public function increment(string $key, int $ttl): array
    {
        $now = microtime(true);
        $record = $this->records[$key] ?? null;
        if ($record === null || $record[1] <= $now) {
            $record = [0, $now + max(1, $ttl)];
        }
        $record[0]++;
        $this->records[$key] = $record;

        return ['totalHits' => $record[0], 'timeToExpire' => max(1, (int) ceil($record[1] - $now))];
    }

    public function clear(): void
    {
        $this->records = [];
    }
}
