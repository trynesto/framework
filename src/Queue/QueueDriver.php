<?php

declare(strict_types=1);

namespace Nesto\Queue;

/**
 * Storage backend for queues. Built-ins: SyncDriver (runs jobs inline), FileDriver (JSON files +
 * flock, for development), DatabaseDriver (PDO `jobs` table with an atomic claim).
 *
 * Lifecycle: push() → pop() claims a ready job (status active) → ack() on success, release() to
 * retry after a delay, fail() when attempts are exhausted. update() persists progress/data.
 */
interface QueueDriver
{
    public function push(Job $job): Job;

    /** Claim the next ready job of $queue (null when empty or paused). */
    public function pop(string $queue): ?Job;

    /** Job completed. Honours opts['removeOnComplete']. */
    public function ack(Job $job): void;

    /** Put the job back for a retry, available after $delayMs. */
    public function release(Job $job, int $delayMs): void;

    /** Final failure. Honours opts['removeOnFail']. */
    public function fail(Job $job): void;

    /** Persist mutable fields (progress, data, status). */
    public function update(Job $job): void;

    /** Waiting + delayed jobs. */
    public function size(string $queue): int;

    public function getJob(string $queue, string $id): ?Job;

    /** Remove finished jobs (`completed`, `failed`, or both when null). Returns how many were removed. */
    public function clean(string $queue, ?string $status = null): int;

    public function pause(string $queue): void;

    public function resume(string $queue): void;

    public function isPaused(string $queue): bool;
}
