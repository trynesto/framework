<?php

declare(strict_types=1);

namespace Nesto\Queue\Drivers;

use Nesto\Queue\Job;
use Nesto\Queue\JobRunner;
use Nesto\Queue\ProcessorRegistry;
use Nesto\Queue\QueueDriver;
use Psr\Log\LoggerInterface;

/**
 * Runs every pushed job immediately, in the same process, through the registered processors
 * (retries happen inline, delays and backoff are ignored). The default driver: perfect for tests
 * and for apps that have no worker yet. Pausing a queue buffers jobs until resume().
 */
final class SyncDriver implements QueueDriver
{
    /** @var array<string, array<string, Job>> queue => id => job */
    private array $jobs = [];
    /** @var array<string, true> */
    private array $paused = [];
    private bool $released = false;
    private readonly JobRunner $runner;

    public function __construct(ProcessorRegistry $registry, ?LoggerInterface $logger = null)
    {
        $this->runner = new JobRunner($registry, $logger);
    }

    public function push(Job $job): Job
    {
        $this->jobs[$job->queue][$job->id] = $job->attach($this);
        if (! $this->isPaused($job->queue)) {
            $this->runNow($job);
        }

        return $job;
    }

    public function pop(string $queue): ?Job
    {
        if ($this->isPaused($queue)) {
            return null;
        }
        foreach ($this->jobs[$queue] ?? [] as $job) {
            if ($job->isReady(PHP_FLOAT_MAX)) {
                $job->status = Job::ACTIVE;

                return $job;
            }
        }

        return null;
    }

    public function ack(Job $job): void
    {
        if ($job->opts['removeOnComplete'] ?? false) {
            unset($this->jobs[$job->queue][$job->id]);
        }
    }

    public function release(Job $job, int $delayMs): void
    {
        $this->released = true;
    }

    public function fail(Job $job): void
    {
        if ($job->opts['removeOnFail'] ?? false) {
            unset($this->jobs[$job->queue][$job->id]);
        }
    }

    public function update(Job $job): void
    {
    }

    public function size(string $queue): int
    {
        $n = 0;
        foreach ($this->jobs[$queue] ?? [] as $job) {
            if (in_array($job->status, [Job::WAITING, Job::DELAYED], true)) {
                $n++;
            }
        }

        return $n;
    }

    public function getJob(string $queue, string $id): ?Job
    {
        return $this->jobs[$queue][$id] ?? null;
    }

    public function clean(string $queue, ?string $status = null): int
    {
        $removed = 0;
        foreach ($this->jobs[$queue] ?? [] as $id => $job) {
            if ($status === null ? $job->isFinished() : $job->status === $status) {
                unset($this->jobs[$queue][$id]);
                $removed++;
            }
        }

        return $removed;
    }

    public function pause(string $queue): void
    {
        $this->paused[$queue] = true;
    }

    /** Resuming runs everything that was buffered while paused. */
    public function resume(string $queue): void
    {
        unset($this->paused[$queue]);
        foreach ($this->jobs[$queue] ?? [] as $job) {
            if ($job->isReady(PHP_FLOAT_MAX)) {
                $this->runNow($job);
            }
        }
    }

    public function isPaused(string $queue): bool
    {
        return isset($this->paused[$queue]);
    }

    private function runNow(Job $job): void
    {
        do {
            $this->released = false;
            $this->runner->run($job, $this);
        } while ($this->released);
    }
}
