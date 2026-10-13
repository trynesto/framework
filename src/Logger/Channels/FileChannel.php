<?php

declare(strict_types=1);

namespace Nesto\Logger\Channels;

/**
 * Appends to a log file (LOG_CHANNEL=file, LOG_PATH, default storage/logs/nesto.log).
 *
 *   new FileChannel('/app/storage/logs/nesto.log');                           // one file
 *   new FileChannel('/app/storage/logs/nesto.log', daily: true, maxFiles: 14); // nesto-2026-10-13.log, 14 days kept
 *
 * The handle stays open between entries (and between requests of a FrankenPHP / RoadRunner worker). With
 * `daily`, the first entry after midnight goes to the new day's file and the files beyond `maxFiles` are
 * deleted. A file removed under us (logrotate, rm) is re-created on the next entry. Every write takes an
 * exclusive lock, so the entries of concurrent PHP-FPM workers never interleave.
 */
final class FileChannel implements LogChannel
{
    /** @var resource|null */
    private $handle = null;
    private ?string $openPath = null;
    private int $checkedAt = 0;

    public function __construct(
        private readonly string $path,
        private readonly bool $daily = false,
        private readonly int $maxFiles = 14,
        private readonly ?int $permission = null,
        private readonly bool $locking = true,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isDaily(): bool
    {
        return $this->daily;
    }

    public function maxFiles(): int
    {
        return $this->maxFiles;
    }

    /** The file the next entry goes to: the path itself, or nesto-YYYY-MM-DD.log next to it when daily. */
    public function currentPath(?int $time = null): string
    {
        if (! $this->daily) {
            return $this->path;
        }
        $info = pathinfo($this->path);
        $extension = isset($info['extension']) ? '.' . $info['extension'] : '';

        return ($info['dirname'] ?? '.') . '/' . $info['filename'] . '-' . date('Y-m-d', $time ?? time()) . $extension;
    }

    public function write(string $level, string $plain, string $colored): void
    {
        $handle = $this->handle();
        if ($this->locking) {
            flock($handle, LOCK_EX);
        }
        fwrite($handle, $plain . PHP_EOL);
        fflush($handle);
        if ($this->locking) {
            flock($handle, LOCK_UN);
        }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
        $this->openPath = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** Delete the oldest daily files beyond maxFiles (0 keeps them all); returns the removed paths. @return list<string> */
    public function prune(): array
    {
        if (! $this->daily || $this->maxFiles < 1) {
            return [];
        }
        $info = pathinfo($this->path);
        $extension = isset($info['extension']) ? '.' . $info['extension'] : '';
        $pattern = ($info['dirname'] ?? '.') . '/' . $info['filename'] . '-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]' . $extension;
        $files = glob($pattern) ?: [];
        rsort($files); // newest first: the dates sort lexically
        $removed = [];
        foreach (array_slice($files, $this->maxFiles) as $file) {
            if (@unlink($file)) {
                $removed[] = $file;
            }
        }

        return $removed;
    }

    /** @return resource */
    private function handle()
    {
        $now = time();
        $target = $this->currentPath($now);
        if ($this->handle !== null && $target === $this->openPath) {
            if ($now === $this->checkedAt) {
                return $this->handle;
            }
            // at most once a second: is the file still there (logrotate, rm)?
            $this->checkedAt = $now;
            clearstatcache(true, $target);
            if (is_file($target)) {
                return $this->handle;
            }
        }
        $this->close();
        $dir = dirname($target);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Logger: cannot create the log directory \"$dir\".");
        }
        $isNew = ! is_file($target);
        $handle = @fopen($target, 'ab');
        if ($handle === false) {
            throw new \RuntimeException("Logger: cannot open \"$target\" for writing.");
        }
        if ($isNew && $this->permission !== null) {
            @chmod($target, $this->permission);
        }
        $this->handle = $handle;
        $this->openPath = $target;
        $this->checkedAt = $now;
        if ($isNew) {
            $this->prune();
        }

        return $handle;
    }
}
