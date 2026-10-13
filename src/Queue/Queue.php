<?php

declare(strict_types=1);

namespace Nesto\Queue;

/**
 * A named queue (Bull's Queue). Obtain it with #[InjectQueue('mail')] or QueueManager::get('mail').
 *
 *   $job = $mail->add('send', ['to' => 'a@b.c'], ['delay' => 1000, 'attempts' => 5, 'priority' => 1]);
 *   $mail->count();              // waiting + delayed
 *   $mail->getJob($job->id);
 *   $mail->pause();  $mail->resume();
 *   $mail->clean();              // drop completed + failed jobs
 */
final class Queue
{
    public function __construct(
        private readonly string $name,
        private readonly QueueDriver $driver,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function driver(): QueueDriver
    {
        return $this->driver;
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $opts delay, attempts, priority, backoff, removeOnComplete, removeOnFail, jobId */
    public function add(string $name, array $data = [], array $opts = []): Job
    {
        return $this->driver->push(Job::create($this->name, $name, $data, $opts));
    }

    public function count(): int
    {
        return $this->driver->size($this->name);
    }

    public function getJob(string $id): ?Job
    {
        return $this->driver->getJob($this->name, $id);
    }

    /** @param 'completed'|'failed'|null $status */
    public function clean(?string $status = null): int
    {
        return $this->driver->clean($this->name, $status);
    }

    public function pause(): void
    {
        $this->driver->pause($this->name);
    }

    public function resume(): void
    {
        $this->driver->resume($this->name);
    }

    public function isPaused(): bool
    {
        return $this->driver->isPaused($this->name);
    }
}
