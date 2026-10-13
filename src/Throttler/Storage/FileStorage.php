<?php

declare(strict_types=1);

namespace Nesto\Throttler\Storage;

use Nesto\Throttler\ThrottlerStorage;

/**
 * Hit counters in small files (`<dir>/<sha1(key)>.json`) guarded by flock(), so every PHP-FPM
 * worker on the host shares the same window. Default directory: sys_get_temp_dir()/nesto-throttler.
 *
 *   new ThrottlerModule(storage: 'file')                 // default dir
 *   new ThrottlerModule(storage: 'file:/var/app/throttle')
 */
final class FileStorage implements ThrottlerStorage
{
    private readonly string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? sys_get_temp_dir() . '/nesto-throttler', '/\\');
        if (! is_dir($this->dir) && ! @mkdir($this->dir, 0775, true) && ! is_dir($this->dir)) {
            throw new \RuntimeException("FileStorage: cannot create directory \"{$this->dir}\".");
        }
    }

    public function increment(string $key, int $ttl): array
    {
        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $path = $this->dir . '/' . sha1($key) . '.json';
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("FileStorage: cannot open \"$path\".");
        }
        try {
            flock($handle, LOCK_EX);
            $now = microtime(true);
            $raw = stream_get_contents($handle) ?: '';
            $record = $raw !== '' ? json_decode($raw, true) : null;
            if (! is_array($record) || ($record['expiresAt'] ?? 0) <= $now) {
                $record = ['hits' => 0, 'expiresAt' => $now + max(1, $ttl)];
            }
            $record['hits']++;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($record));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return ['totalHits' => (int) $record['hits'], 'timeToExpire' => max(1, (int) ceil($record['expiresAt'] - $now))];
    }

    public function clear(): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }
}
