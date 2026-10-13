<?php

declare(strict_types=1);

namespace Nesto\Cache\Stores;

use Psr\SimpleCache\CacheInterface;

/**
 * In-memory PSR-16 store with per-item TTL and an optional size cap (oldest entry evicted first).
 * Lives for the current process only — ideal for tests and CLI runs.
 *
 *   $cache = new ArrayStore(max: 1000, defaultTtl: 60);
 *   $cache->set('cats', $cats, 10);
 *   $cache->get('cats');   // $cats for 10 seconds, then null
 */
final class ArrayStore implements CacheInterface
{
    use Psr16Support;

    /** @var array<string, array{0: ?float, 1: mixed}> key => [expiresAt, value] */
    private array $items = [];

    public function __construct(private readonly ?int $max = null, ?int $defaultTtl = null)
    {
        $this->defaultTtl = $defaultTtl;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        if (! isset($this->items[$key])) {
            return $default;
        }
        [$expires, $value] = $this->items[$key];
        if ($expires !== null && $expires <= microtime(true)) {
            unset($this->items[$key]);

            return $default;
        }

        return $value;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        unset($this->items[$key]);
        if ($this->max !== null && $this->max > 0) {
            while (count($this->items) >= $this->max) {
                unset($this->items[array_key_first($this->items)]);
            }
        }
        $this->items[$key] = [$this->expiresAt($ttl), $value];

        return true;
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);
        if (! isset($this->items[$key])) {
            return false;
        }
        $expires = $this->items[$key][0];
        if ($expires !== null && $expires <= microtime(true)) {
            unset($this->items[$key]);

            return false;
        }

        return true;
    }

    public function count(): int
    {
        foreach (array_keys($this->items) as $key) {
            $this->has($key);
        }

        return count($this->items);
    }
}
