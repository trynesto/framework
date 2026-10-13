<?php

declare(strict_types=1);

namespace Nesto\Cache\Stores;

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 store that keeps one serialized file per key (`<dir>/<sha1(key)>.cache`), written
 * atomically (temp file + rename) so concurrent PHP-FPM workers never read a half-written entry.
 * Expired files are removed on read; `gc()` sweeps them all.
 *
 *   $cache = new FileStore(__DIR__ . '/storage/cache', defaultTtl: 300);
 */
final class FileStore implements CacheInterface
{
    use Psr16Support;

    private readonly string $dir;

    public function __construct(string $dir, ?int $defaultTtl = null)
    {
        $this->dir = rtrim($dir, '/\\');
        $this->defaultTtl = $defaultTtl;
        $this->ensureDir();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        $entry = $this->read($key);

        return $entry === null ? $default : $entry['v'];
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        $this->ensureDir();
        $payload = serialize(['e' => $this->expiresAt($ttl), 'v' => $value, 'k' => $key]);
        $path = $this->path($key);
        $tmp = tempnam($this->dir, 'tmp');
        if ($tmp === false || file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return false;
        }
        if (! rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        $path = $this->path($key);

        return ! is_file($path) || @unlink($path);
    }

    public function clear(): bool
    {
        foreach (glob($this->dir . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }

        return true;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);

        return $this->read($key) !== null;
    }

    /** Remove every expired file; returns how many were deleted. */
    public function gc(): int
    {
        $removed = 0;
        foreach (glob($this->dir . '/*.cache') ?: [] as $file) {
            $entry = $this->readFile($file);
            if ($entry === null) {
                @unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    private function ensureDir(): void
    {
        if (! is_dir($this->dir) && ! @mkdir($this->dir, 0775, true) && ! is_dir($this->dir)) {
            throw new \RuntimeException("FileStore: cannot create directory \"{$this->dir}\".");
        }
    }

    public function path(string $key): string
    {
        return $this->dir . '/' . sha1($key) . '.cache';
    }

    /** @return array{e: ?float, v: mixed, k: string}|null */
    private function read(string $key): ?array
    {
        return $this->readFile($this->path($key));
    }

    /** @return array{e: ?float, v: mixed, k: string}|null */
    private function readFile(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $entry = @unserialize($raw, ['allowed_classes' => true]);
        if (! is_array($entry) || ! array_key_exists('v', $entry)) {
            @unlink($path);

            return null;
        }
        if ($entry['e'] !== null && $entry['e'] <= microtime(true)) {
            @unlink($path);

            return null;
        }

        return $entry;
    }
}
