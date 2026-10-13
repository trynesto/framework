<?php

declare(strict_types=1);

namespace Nesto\Cache\Stores;

use Nesto\Cache\InvalidArgumentException;

/**
 * Shared PSR-16 plumbing for the built-in stores: key validation, TTL normalisation and the
 * *Multiple() methods implemented on top of get/set/delete.
 */
trait Psr16Support
{
    /** Seconds; null or 0 = keep forever. */
    private ?int $defaultTtl = null;

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get((string) $key, $default);
        }

        return $out;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->delete((string) $key) && $ok;
        }

        return $ok;
    }

    protected function validateKey(string $key): void
    {
        if ($key === '' || preg_match('#[{}()/\\\\@:]#', $key)) {
            throw new InvalidArgumentException(sprintf('Invalid cache key "%s": keys must be non-empty and must not contain {}()/\\@:', $key));
        }
    }

    /** Absolute expiry timestamp (float) or null for "forever". */
    protected function expiresAt(null|int|\DateInterval $ttl): ?float
    {
        $seconds = $this->ttlSeconds($ttl) ?? $this->defaultTtl;
        if ($seconds === null || $seconds === 0) {
            return null;
        }

        return microtime(true) + $seconds;
    }

    protected function ttlSeconds(null|int|\DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }
        if ($ttl instanceof \DateInterval) {
            $now = new \DateTimeImmutable;

            return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }
        if ($ttl < 0) {
            throw new InvalidArgumentException('Cache TTL must not be negative.');
        }

        return $ttl;
    }
}
