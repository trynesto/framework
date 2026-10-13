<?php

declare(strict_types=1);

namespace Nesto\Cache\Stores;

use Psr\SimpleCache\CacheInterface;

/**
 * Wraps any third-party PSR-16 cache (Redis, Memcached, APCu via symfony/cache, cache/* …) so it
 * can be the CacheModule store, optionally namespacing keys with a prefix.
 *
 *   $redis = new \Symfony\Component\Cache\Psr16Cache(new RedisAdapter($client));
 *   CacheModule::register(['store' => new Psr16Adapter($redis, prefix: 'app_'), 'ttl' => 60]);
 *
 * `clear()` is forwarded to the inner cache and therefore ignores the prefix.
 */
final class Psr16Adapter implements CacheInterface
{
    use Psr16Support;

    public function __construct(
        private readonly CacheInterface $inner,
        private readonly string $prefix = '',
        ?int $defaultTtl = null,
    ) {
        $this->defaultTtl = $defaultTtl;
    }

    public function inner(): CacheInterface
    {
        return $this->inner;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->inner->get($this->prefix . $key, $default);
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $seconds = $this->ttlSeconds($ttl) ?? $this->defaultTtl;

        return $this->inner->set($this->prefix . $key, $value, $seconds === 0 ? null : $seconds);
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($this->prefix . $key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function has(string $key): bool
    {
        return $this->inner->has($this->prefix . $key);
    }
}
