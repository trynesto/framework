<?php

declare(strict_types=1);

namespace Nesto\Queue;

use Psr\Log\LoggerInterface;

/**
 * Runs one job through its #[Process] handler with the attempt / backoff policy and the
 * OnQueueActive / OnQueueCompleted / OnQueueFailed hooks. Shared by Worker and SyncDriver.
 */
final class JobRunner
{
    public function __construct(
        private readonly ProcessorRegistry $registry,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** @return bool true when the job completed, false when it failed (retry scheduled or final) */
    public function run(Job $job, QueueDriver $driver): bool
    {
        $job->attach($driver);
        $handler = $this->registry->handlerFor($job->queue, $job->name);
        if ($handler === null) {
            $job->status = Job::FAILED;
            $job->failedReason = "No processor registered for job \"{$job->name}\" on queue \"{$job->queue}\".";
            $job->finishedOn = microtime(true);
            $driver->fail($job);
            $this->failureLogger()->warning($job->failedReason, ['context' => 'Queue']);

            return false;
        }

        $job->status = Job::ACTIVE;
        $job->processedOn = microtime(true);
        $job->attemptsMade++;
        $job->failedReason = null;
        $this->registry->emit($job->queue, 'active', $job);

        try {
            $result = $handler($job);
        } catch (\Throwable $e) {
            return $this->handleFailure($job, $driver, $e);
        }

        $job->returnvalue = $result;
        $job->status = Job::COMPLETED;
        $job->finishedOn = microtime(true);
        $driver->ack($job);
        $this->registry->emit($job->queue, 'completed', $job, $result);

        return true;
    }

    private function handleFailure(Job $job, QueueDriver $driver, \Throwable $e): bool
    {
        $job->failedReason = $e->getMessage();
        if ($job->attemptsMade < $job->attempts()) {
            $delay = self::backoff($job);
            $job->status = $delay > 0 ? Job::DELAYED : Job::WAITING;
            $driver->release($job, $delay);
        } else {
            $job->status = Job::FAILED;
            $job->finishedOn = microtime(true);
            $driver->fail($job);
        }
        $this->failureLogger()->error("Job \"{$job->name}\" ({$job->id}) on queue \"{$job->queue}\" failed: {$e->getMessage()}", ['exception' => $e, 'context' => 'Queue']);
        $this->registry->emit($job->queue, 'failed', $job, $e);

        return false;
    }

    /** Failures always reach a log: the injected PSR-3 logger, else the framework Logger (LOG_CHANNEL). */
    private function failureLogger(): LoggerInterface
    {
        return $this->logger ?? new \Nesto\Logger\Logger('Queue');
    }

    /** Delay in ms before the next attempt from opts['backoff'] (int = fixed, or ['type' => 'exponential', 'delay' => ms]). */
    public static function backoff(Job $job): int
    {
        $backoff = $job->opts['backoff'] ?? 0;
        if (is_int($backoff) || is_float($backoff) || is_numeric($backoff)) {
            return max(0, (int) $backoff);
        }
        if (is_array($backoff)) {
            $delay = max(0, (int) ($backoff['delay'] ?? 0));
            if (($backoff['type'] ?? 'fixed') === 'exponential') {
                return $delay * (2 ** max(0, $job->attemptsMade - 1));
            }

            return $delay;
        }

        return 0;
    }
}
