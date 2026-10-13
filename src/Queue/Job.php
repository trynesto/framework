<?php

declare(strict_types=1);

namespace Nesto\Queue;

/**
 * A unit of work (Bull's Job). Created by Queue::add() and handed to #[Process] methods.
 *
 *   $job->id; $job->name; $job->data; $job->attemptsMade; $job->opts['attempts'];
 *   $job->progress(50);            // persist progress through the driver
 *   $job->progress();              // 50
 *
 * Options (all optional): delay (ms), attempts (default 3), priority (lower runs first, default 0),
 * backoff (ms, or ['type' => 'fixed'|'exponential', 'delay' => ms]), removeOnComplete, removeOnFail.
 */
final class Job
{
    public const WAITING = 'waiting';
    public const DELAYED = 'delayed';
    public const ACTIVE = 'active';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    public const DEFAULT_OPTS = ['delay' => 0, 'attempts' => 3, 'priority' => 0, 'backoff' => 0, 'removeOnComplete' => false, 'removeOnFail' => false];

    private ?QueueDriver $driver = null;

    /** @param array<string, mixed> $data @param array<string, mixed> $opts */
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
        public readonly string $name,
        public array $data = [],
        public array $opts = [],
        public string $status = self::WAITING,
        public int $attemptsMade = 0,
        public float $timestamp = 0.0,
        public float $availableAt = 0.0,
        public ?float $processedOn = null,
        public ?float $finishedOn = null,
        public ?string $failedReason = null,
        public mixed $returnvalue = null,
        private mixed $progressValue = 0,
    ) {
        $this->opts += self::DEFAULT_OPTS;
        if ($this->timestamp === 0.0) {
            $this->timestamp = microtime(true);
        }
        if ($this->availableAt === 0.0) {
            $this->availableAt = $this->timestamp + ((int) $this->opts['delay']) / 1000;
        }
        if ($this->status === self::WAITING && (int) $this->opts['delay'] > 0) {
            $this->status = self::DELAYED;
        }
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $opts */
    public static function create(string $queue, string $name, array $data = [], array $opts = []): self
    {
        $id = $opts['jobId'] ?? sprintf('%d-%s', (int) (microtime(true) * 1000), bin2hex(random_bytes(4)));
        unset($opts['jobId']);

        return new self((string) $id, $queue, $name, $data, $opts);
    }

    /** Get the progress, or set it (persisted when the job came from a driver). */
    public function progress(mixed $value = null): mixed
    {
        if (func_num_args() === 0) {
            return $this->progressValue;
        }
        $this->progressValue = $value;
        $this->driver?->update($this);

        return $value;
    }

    public function attempts(): int
    {
        return max(1, (int) $this->opts['attempts']);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }

    /** Completed or finally failed. */
    public function isFinished(): bool
    {
        return $this->finishedOn !== null;
    }

    public function isReady(float $now): bool
    {
        return in_array($this->status, [self::WAITING, self::DELAYED], true) && $this->availableAt <= $now;
    }

    /** @internal drivers attach themselves so progress() can persist */
    public function attach(QueueDriver $driver): self
    {
        $this->driver = $driver;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'queue' => $this->queue,
            'name' => $this->name,
            'data' => $this->data,
            'opts' => $this->opts,
            'status' => $this->status,
            'attemptsMade' => $this->attemptsMade,
            'timestamp' => $this->timestamp,
            'availableAt' => $this->availableAt,
            'processedOn' => $this->processedOn,
            'finishedOn' => $this->finishedOn,
            'failedReason' => $this->failedReason,
            'returnvalue' => $this->returnvalue,
            'progress' => $this->progressValue,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            queue: (string) $row['queue'],
            name: (string) $row['name'],
            data: (array) ($row['data'] ?? []),
            opts: (array) ($row['opts'] ?? []),
            status: (string) ($row['status'] ?? self::WAITING),
            attemptsMade: (int) ($row['attemptsMade'] ?? 0),
            timestamp: (float) ($row['timestamp'] ?? 0),
            availableAt: (float) ($row['availableAt'] ?? 0),
            processedOn: isset($row['processedOn']) ? (float) $row['processedOn'] : null,
            finishedOn: isset($row['finishedOn']) ? (float) $row['finishedOn'] : null,
            failedReason: $row['failedReason'] ?? null,
            returnvalue: $row['returnvalue'] ?? null,
            progressValue: $row['progress'] ?? 0,
        );
    }
}
