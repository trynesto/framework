<?php

declare(strict_types=1);

namespace Nesto\Tests\Cache;

use Nesto\Cache\Redis\RedisClient;

/** In-memory Redis for RedisStore unit tests: strings with optional expiry, glob SCAN, a call log. */
final class FakeRedisClient implements RedisClient
{
    /** @var array<string, array{0: string, 1: ?int}> key => [value, expires at] */
    public array $data = [];
    /** @var list<string> */
    public array $calls = [];
    public int $now = 1_000_000;

    public function get(string $key): ?string
    {
        $this->calls[] = "GET $key";

        return $this->live($key) ? $this->data[$key][0] : null;
    }

    public function mget(array $keys): array
    {
        $this->calls[] = 'MGET ' . implode(' ', $keys);

        return array_map(fn (string $k) => $this->live($k) ? $this->data[$k][0] : null, $keys);
    }

    public function set(string $key, string $value, ?int $ttl = null): bool
    {
        $this->calls[] = ($ttl !== null ? "SETEX $key $ttl" : "SET $key");
        $this->data[$key] = [$value, $ttl !== null ? $this->now + $ttl : null];

        return true;
    }

    public function del(array $keys): int
    {
        $this->calls[] = 'DEL ' . implode(' ', $keys);
        $removed = 0;
        foreach ($keys as $key) {
            if (isset($this->data[$key])) {
                unset($this->data[$key]);
                $removed++;
            }
        }

        return $removed;
    }

    public function exists(string $key): bool
    {
        return $this->live($key);
    }

    public function scan(string $pattern): iterable
    {
        $this->calls[] = "SCAN $pattern";
        foreach (array_keys($this->data) as $key) {
            if (fnmatch($pattern, (string) $key, FNM_NOESCAPE) || fnmatch($pattern, (string) $key)) {
                yield (string) $key;
            }
        }
    }

    public function flushDb(): bool
    {
        $this->calls[] = 'FLUSHDB';
        $this->data = [];

        return true;
    }

    private function live(string $key): bool
    {
        if (! isset($this->data[$key])) {
            return false;
        }
        if ($this->data[$key][1] !== null && $this->data[$key][1] <= $this->now) {
            unset($this->data[$key]);

            return false;
        }

        return true;
    }
}
