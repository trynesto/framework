<?php

declare(strict_types=1);

namespace Nesto\Queue\Drivers;

use Nesto\Queue\Job;
use Nesto\Queue\QueueDriver;
use Nesto\Queue\QueueException;

/**
 * One JSON file per job under `<dir>/<queue>/<id>.json`; a per-queue lock file (flock) makes
 * pop() safe across several worker processes on the same host. Good enough for development and
 * small single-host apps; use DatabaseDriver (or a real broker) beyond that.
 *
 *   new QueueModule(driver: 'file', path: __DIR__ . '/storage/queue')
 *
 * Jobs claimed by a worker that died stay `active` — call `releaseStale($queue, $seconds)` to recover them.
 */
final class FileDriver implements QueueDriver
{
    private readonly string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? sys_get_temp_dir() . '/nesto-queue', '/\\');
        if (! is_dir($this->dir) && ! @mkdir($this->dir, 0775, true) && ! is_dir($this->dir)) {
            throw new QueueException("FileDriver: cannot create directory \"{$this->dir}\".");
        }
    }

    public function push(Job $job): Job
    {
        $this->write($job);

        return $job->attach($this);
    }

    public function pop(string $queue): ?Job
    {
        if ($this->isPaused($queue)) {
            return null;
        }

        return $this->locked($queue, function () use ($queue): ?Job {
            $now = microtime(true);
            $best = null;
            foreach ($this->all($queue) as $job) {
                if (! $job->isReady($now)) {
                    continue;
                }
                if ($best === null || self::compare($job, $best) < 0) {
                    $best = $job;
                }
            }
            if ($best === null) {
                return null;
            }
            $best->status = Job::ACTIVE;
            $best->processedOn = $now;
            $this->write($best);

            return $best->attach($this);
        });
    }

    public function ack(Job $job): void
    {
        if ($job->opts['removeOnComplete'] ?? false) {
            @unlink($this->path($job->queue, $job->id));

            return;
        }
        $this->write($job);
    }

    public function release(Job $job, int $delayMs): void
    {
        $job->availableAt = microtime(true) + $delayMs / 1000;
        $job->status = $delayMs > 0 ? Job::DELAYED : Job::WAITING;
        $this->write($job);
    }

    public function fail(Job $job): void
    {
        if ($job->opts['removeOnFail'] ?? false) {
            @unlink($this->path($job->queue, $job->id));

            return;
        }
        $this->write($job);
    }

    public function update(Job $job): void
    {
        $this->write($job);
    }

    public function size(string $queue): int
    {
        $n = 0;
        foreach ($this->all($queue) as $job) {
            if (in_array($job->status, [Job::WAITING, Job::DELAYED], true)) {
                $n++;
            }
        }

        return $n;
    }

    public function getJob(string $queue, string $id): ?Job
    {
        return $this->read($this->path($queue, $id))?->attach($this);
    }

    public function clean(string $queue, ?string $status = null): int
    {
        $removed = 0;
        foreach ($this->all($queue) as $job) {
            if ($status === null ? $job->isFinished() : $job->status === $status) {
                @unlink($this->path($queue, $job->id));
                $removed++;
            }
        }

        return $removed;
    }

    public function pause(string $queue): void
    {
        $this->queueDir($queue);
        touch($this->queueDir($queue) . '/.paused');
    }

    public function resume(string $queue): void
    {
        @unlink($this->queueDir($queue) . '/.paused');
    }

    public function isPaused(string $queue): bool
    {
        return is_file($this->dir . '/' . $this->safe($queue) . '/.paused');
    }

    /** Put jobs that have been `active` for longer than $seconds back to waiting. */
    public function releaseStale(string $queue, int $seconds = 300): int
    {
        return $this->locked($queue, function () use ($queue, $seconds): int {
            $n = 0;
            $limit = microtime(true) - $seconds;
            foreach ($this->all($queue) as $job) {
                if ($job->status === Job::ACTIVE && ($job->processedOn ?? 0) < $limit) {
                    $job->status = Job::WAITING;
                    $job->availableAt = microtime(true);
                    $this->write($job);
                    $n++;
                }
            }

            return $n;
        });
    }

    // ---- internals ---------------------------------------------------------------------

    /** @return list<Job> */
    private function all(string $queue): array
    {
        $out = [];
        foreach (glob($this->queueDir($queue) . '/*.json') ?: [] as $file) {
            $job = $this->read($file);
            if ($job !== null) {
                $out[] = $job;
            }
        }

        return $out;
    }

    private static function compare(Job $a, Job $b): int
    {
        return [$a->opts['priority'], $a->availableAt, $a->timestamp, $a->id] <=> [$b->opts['priority'], $b->availableAt, $b->timestamp, $b->id];
    }

    private function read(string $file): ?Job
    {
        if (! is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        $row = $raw ? json_decode($raw, true) : null;

        return is_array($row) && isset($row['id']) ? Job::fromArray($row) : null;
    }

    private function write(Job $job): void
    {
        $path = $this->path($job->queue, $job->id);
        $tmp = $path . '.' . bin2hex(random_bytes(3)) . '.tmp';
        file_put_contents($tmp, json_encode($job->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        rename($tmp, $path);
    }

    private function path(string $queue, string $id): string
    {
        return $this->queueDir($queue) . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $id) . '.json';
    }

    private function queueDir(string $queue): string
    {
        $dir = $this->dir . '/' . $this->safe($queue);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private function safe(string $queue): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $queue) ?? $queue;
    }

    /** @template T @param callable(): T $fn @return T */
    private function locked(string $queue, callable $fn): mixed
    {
        $handle = fopen($this->queueDir($queue) . '/.lock', 'c');
        if ($handle === false) {
            throw new QueueException("FileDriver: cannot open lock for queue \"$queue\".");
        }
        try {
            flock($handle, LOCK_EX);

            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
