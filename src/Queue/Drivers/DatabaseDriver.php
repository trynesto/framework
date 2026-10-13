<?php

declare(strict_types=1);

namespace Nesto\Queue\Drivers;

use Nesto\Queue\Job;
use Nesto\Queue\QueueDriver;
use Nesto\Queue\QueueException;
use PDO;

/**
 * Jobs in a `jobs` table (created lazily; SQLite, MySQL and PostgreSQL). Workers claim a job with
 * `UPDATE ... WHERE id = ? AND reserved_at IS NULL`, so several workers never run the same job twice
 * without needing SELECT ... FOR UPDATE SKIP LOCKED.
 *
 *   new QueueModule(driver: 'database', connection: 'sqlite:/var/app/queue.sqlite')
 *   QueueModule::forRoot(['driver' => 'database', 'pdo' => $pdo])         // reuse a connection
 *   new DatabaseDriver($pdo, table: 'queue_jobs')
 *
 * Pause state is stored as a marker row (`__paused__:<queue>`) so it is shared between processes.
 */
final class DatabaseDriver implements QueueDriver
{
    private readonly PDO $pdo;
    private bool $ready = false;

    public function __construct(PDO|string $connection, private readonly string $table = 'jobs')
    {
        $this->pdo = $connection instanceof PDO ? $connection : new PDO($connection);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new QueueException("DatabaseDriver: invalid table name \"$table\".");
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function push(Job $job): Job
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare("INSERT INTO {$this->table} (id, queue, name, payload, status, priority, attempts_made, available_at, reserved_at, created_at, finished_at, failed_reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NULL, NULL)");
        $stmt->execute([$job->id, $job->queue, $job->name, $this->encode($job), $job->status, (int) $job->opts['priority'], $job->attemptsMade, $job->availableAt, $job->timestamp]);

        return $job->attach($this);
    }

    public function pop(string $queue): ?Job
    {
        $this->ensureTable();
        if ($this->isPaused($queue)) {
            return null;
        }
        for ($try = 0; $try < 5; $try++) {
            $now = microtime(true);
            $select = $this->pdo->prepare("SELECT id FROM {$this->table} WHERE queue = ? AND status IN ('waiting', 'delayed') AND available_at <= ? ORDER BY priority ASC, available_at ASC, created_at ASC, id ASC LIMIT 1");
            $select->execute([$queue, $now]);
            $id = $select->fetchColumn();
            if ($id === false) {
                return null;
            }
            $claim = $this->pdo->prepare("UPDATE {$this->table} SET status = 'active', reserved_at = ? WHERE id = ? AND reserved_at IS NULL");
            $claim->execute([$now, $id]);
            if ($claim->rowCount() === 1) {
                $job = $this->getJob($queue, (string) $id);
                if ($job !== null) {
                    $job->status = Job::ACTIVE;
                    $job->processedOn = $now;

                    return $job;
                }
            }
        }

        return null;
    }

    public function ack(Job $job): void
    {
        if ($job->opts['removeOnComplete'] ?? false) {
            $this->remove($job);

            return;
        }
        $this->store($job, reserved: null);
    }

    public function release(Job $job, int $delayMs): void
    {
        $job->availableAt = microtime(true) + $delayMs / 1000;
        $job->status = $delayMs > 0 ? Job::DELAYED : Job::WAITING;
        $this->store($job, reserved: null);
    }

    public function fail(Job $job): void
    {
        if ($job->opts['removeOnFail'] ?? false) {
            $this->remove($job);

            return;
        }
        $this->store($job, reserved: null);
    }

    public function update(Job $job): void
    {
        $this->store($job, reserved: $job->status === Job::ACTIVE ? ($job->processedOn ?? microtime(true)) : null);
    }

    public function size(string $queue): int
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->table} WHERE queue = ? AND status IN ('waiting', 'delayed')");
        $stmt->execute([$queue]);

        return (int) $stmt->fetchColumn();
    }

    public function getJob(string $queue, string $id): ?Job
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare("SELECT payload FROM {$this->table} WHERE id = ? AND queue = ?");
        $stmt->execute([$id, $queue]);
        $payload = $stmt->fetchColumn();
        if ($payload === false) {
            return null;
        }
        $row = json_decode((string) $payload, true);

        return is_array($row) ? Job::fromArray($row)->attach($this) : null;
    }

    public function clean(string $queue, ?string $status = null): int
    {
        $this->ensureTable();
        if ($status === null) {
            $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE queue = ? AND status IN ('completed', 'failed')");
            $stmt->execute([$queue]);
        } else {
            $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE queue = ? AND status = ?");
            $stmt->execute([$queue, $status]);
        }

        return $stmt->rowCount();
    }

    public function pause(string $queue): void
    {
        $this->ensureTable();
        if ($this->isPaused($queue)) {
            return;
        }
        $stmt = $this->pdo->prepare("INSERT INTO {$this->table} (id, queue, name, payload, status, priority, attempts_made, available_at, reserved_at, created_at, finished_at, failed_reason) VALUES (?, ?, '', '{}', 'paused', 0, 0, 0, NULL, ?, NULL, NULL)");
        $stmt->execute(['__paused__:' . $queue, $queue, microtime(true)]);
    }

    public function resume(string $queue): void
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = ?");
        $stmt->execute(['__paused__:' . $queue]);
    }

    public function isPaused(string $queue): bool
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->table} WHERE id = ?");
        $stmt->execute(['__paused__:' . $queue]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** Put jobs reserved for longer than $seconds back to waiting (crashed workers). */
    public function releaseStale(string $queue, int $seconds = 300): int
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare("UPDATE {$this->table} SET status = 'waiting', reserved_at = NULL, available_at = ? WHERE queue = ? AND status = 'active' AND reserved_at < ?");
        $now = microtime(true);
        $stmt->execute([$now, $queue, $now - $seconds]);

        return $stmt->rowCount();
    }

    // ---- internals -------------------------------------------------------------------------

    private function store(Job $job, ?float $reserved): void
    {
        $stmt = $this->pdo->prepare("UPDATE {$this->table} SET payload = ?, status = ?, priority = ?, attempts_made = ?, available_at = ?, reserved_at = ?, finished_at = ?, failed_reason = ? WHERE id = ?");
        $stmt->execute([$this->encode($job), $job->status, (int) $job->opts['priority'], $job->attemptsMade, $job->availableAt, $reserved, $job->finishedOn, $job->failedReason, $job->id]);
    }

    private function remove(Job $job): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = ?");
        $stmt->execute([$job->id]);
    }

    private function encode(Job $job): string
    {
        return json_encode($job->toArray(), JSON_THROW_ON_ERROR);
    }

    private function ensureTable(): void
    {
        if ($this->ready) {
            return;
        }
        $this->ready = true;
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table} (
            id VARCHAR(191) PRIMARY KEY,
            queue VARCHAR(191) NOT NULL,
            name VARCHAR(191) NOT NULL,
            payload TEXT NOT NULL,
            status VARCHAR(20) NOT NULL,
            priority INTEGER NOT NULL DEFAULT 0,
            attempts_made INTEGER NOT NULL DEFAULT 0,
            available_at DOUBLE PRECISION NOT NULL,
            reserved_at DOUBLE PRECISION NULL,
            created_at DOUBLE PRECISION NOT NULL,
            finished_at DOUBLE PRECISION NULL,
            failed_reason TEXT NULL
        )");
        try {
            $this->pdo->exec("CREATE INDEX {$this->table}_queue_status_idx ON {$this->table} (queue, status, available_at)");
        } catch (\PDOException) {
            // index already exists (MySQL has no IF NOT EXISTS for indexes)
        }
    }
}
