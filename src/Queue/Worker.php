<?php

declare(strict_types=1);

namespace Nesto\Queue;

use Psr\Log\LoggerInterface;

/**
 * Pulls jobs from the driver and runs them through the processors (`php nesto queue:work`).
 *
 *   $worker->work(['mail', 'video'], ['once' => true]);          // drain what is waiting, then return
 *   $worker->work([], ['sleep' => 2, 'maxJobs' => 100]);          // every registered queue, poll every 2s, stop after 100 jobs
 *   $worker->stop();                                              // from a signal handler
 *
 * Options: once (drain and return, default false), sleep (seconds between empty polls, default 1),
 * maxJobs (stop after N job attempts, default unlimited). Queues default to every queue with a #[Processor].
 * processed() counts attempts: a job retried twice counts three times.
 */
final class Worker
{
    private bool $shouldStop = false;
    private int $processed = 0;
    private readonly JobRunner $runner;

    public function __construct(
        private readonly QueueDriver $driver,
        private readonly ProcessorRegistry $registry,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ProcessorExplorer $explorer = null,
    ) {
        $this->runner = new JobRunner($registry, $logger);
    }

    /**
     * @param list<string> $queues
     * @param array{once?: bool, sleep?: int|float, maxJobs?: ?int} $opts
     */
    public function work(array $queues = [], array $opts = []): void
    {
        $once = (bool) ($opts['once'] ?? false);
        $sleep = (float) ($opts['sleep'] ?? 1);
        $maxJobs = isset($opts['maxJobs']) ? (int) $opts['maxJobs'] : null;
        $this->explorer?->explore();
        $queues = $queues !== [] ? $queues : $this->registry->queues();
        $this->shouldStop = false;
        $this->installSignalHandlers();

        $this->logger?->info('Worker started for queues: ' . implode(', ', $queues ?: ['(none)']), ['context' => 'Queue']);

        while (! $this->shouldStop) {
            $found = false;
            foreach ($queues as $queue) {
                while (! $this->shouldStop && ($job = $this->driver->pop($queue)) !== null) {
                    $found = true;
                    $this->runner->run($job, $this->driver);
                    $this->processed++;
                    if ($maxJobs !== null && $this->processed >= $maxJobs) {
                        return;
                    }
                }
            }
            if ($once) {
                return;
            }
            if (! $found) {
                usleep((int) ($sleep * 1_000_000));
            }
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    public function processed(): int
    {
        return $this->processed;
    }

    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }
        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, fn () => $this->stop());
        }
    }
}
