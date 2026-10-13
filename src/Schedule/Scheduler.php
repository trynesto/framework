<?php

declare(strict_types=1);

namespace Nesto\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;

/**
 * Runs what the SchedulerRegistry holds. Three ways to drive it:
 *
 *   $scheduler->tick();                 // run everything due *right now* (in-memory bookkeeping), returns the names that ran
 *   $scheduler->run();                  // long-running loop, ticks every second → `php nesto schedule:work`
 *   $scheduler->runDueOnce();           // one pass with bookkeeping in a file → system cron: `* * * * * php nesto schedule:run`
 *
 * Cron jobs run at most once per matching minute (or second for 6-field expressions); intervals and
 * timeouts are measured from the first tick (`startedAt`). The file store (default
 * `sys_get_temp_dir()/nesto-schedule/state.json`, locked with flock) is what stops `schedule:run`
 * from double-running a job when the system cron fires twice in the same minute.
 *
 * With a 1-second tick, intervals shorter than 1000 ms fire once per tick. Exceptions thrown by a
 * job are logged (PSR-3 logger when available) and never stop the scheduler.
 */
final class Scheduler
{
    /** @var array{cron: array<string, string>, intervals: array<string, float>, timeouts: array<string, float>, startedAt: ?float} */
    private array $state = ['cron' => [], 'intervals' => [], 'timeouts' => [], 'startedAt' => null];
    private readonly string $storagePath;
    /** @var array<string, \Throwable> last error per job name */
    private array $errors = [];

    public function __construct(
        private readonly SchedulerRegistry $registry,
        private readonly CronParser $parser = new CronParser,
        ?string $storagePath = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ScheduleExplorer $explorer = null,
    ) {
        $this->storagePath = rtrim($storagePath ?? sys_get_temp_dir() . '/nesto-schedule', '/');
    }

    public function registry(): SchedulerRegistry
    {
        return $this->registry;
    }

    /**
     * Every registered job (after discovering #[Cron] / #[Interval] / #[Timeout] on providers).
     *
     * @return list<array{type: 'cron'|'interval'|'timeout', name: string, schedule: string}>
     */
    public function jobs(): array
    {
        $this->explorer?->explore();
        $jobs = [];
        foreach ($this->registry->getCronJobs() as $name => $job) {
            $jobs[] = ['type' => 'cron', 'name' => $name, 'schedule' => $job['expression'] . ($job['timeZone'] !== null ? " ({$job['timeZone']})" : '')];
        }
        foreach ($this->registry->getIntervals() as $name => $job) {
            $jobs[] = ['type' => 'interval', 'name' => $name, 'schedule' => "every {$job['milliseconds']} ms"];
        }
        foreach ($this->registry->getTimeouts() as $name => $job) {
            $jobs[] = ['type' => 'timeout', 'name' => $name, 'schedule' => "once after {$job['milliseconds']} ms"];
        }

        return $jobs;
    }

    public function storagePath(): string
    {
        return $this->storagePath;
    }

    /** Run every job due at $now (in-memory state). @return list<string> names of the jobs that ran */
    public function tick(?DateTimeImmutable $now = null): array
    {
        return $this->advance($now ?? new DateTimeImmutable, $this->state);
    }

    /** One pass with the state persisted in a file (safe to call from a system cron entry every minute). @return list<string> */
    public function runDueOnce(?DateTimeImmutable $now = null): array
    {
        if (! is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0775, true);
        }
        $file = $this->storagePath . '/state.json';
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Scheduler: cannot open state file \"$file\".");
        }
        try {
            flock($handle, LOCK_EX);
            $raw = stream_get_contents($handle) ?: '';
            $decoded = $raw !== '' ? json_decode($raw, true) : null;
            $state = is_array($decoded) ? $decoded + $this->state : $this->state;
            $state['cron'] = (array) ($state['cron'] ?? []);
            $state['intervals'] = (array) ($state['intervals'] ?? []);
            $state['timeouts'] = (array) ($state['timeouts'] ?? []);

            $ran = $this->advance($now ?? new DateTimeImmutable, $state);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return $ran;
    }

    /**
     * Block forever, ticking once per second (for `php nesto schedule:work`).
     *
     * @param (callable(list<string> $ran, array<string, \Throwable> $errors): void)|null $onRun called after every tick that ran something
     */
    public function run(?callable $onRun = null): never
    {
        $this->logger?->info('Scheduler started', ['context' => 'Scheduler']);
        while (true) {
            $ran = $this->tick();
            if ($onRun !== null && $ran !== []) {
                $onRun($ran, array_intersect_key($this->errors, array_flip($ran)));
            }
            $wait = 1_000_000 - (int) ((microtime(true) - floor(microtime(true))) * 1_000_000);
            usleep(max(10_000, $wait));
        }
    }

    /** Forget in-memory bookkeeping (and the state file when $includingFile is true). */
    public function reset(bool $includingFile = false): void
    {
        $this->state = ['cron' => [], 'intervals' => [], 'timeouts' => [], 'startedAt' => null];
        $this->errors = [];
        if ($includingFile && is_file($this->storagePath . '/state.json')) {
            unlink($this->storagePath . '/state.json');
        }
    }

    /** Last exception thrown per job name. @return array<string, \Throwable> */
    public function errors(): array
    {
        return $this->errors;
    }

    // ---- internals -------------------------------------------------------------------

    /** @return list<string> */
    private function advance(DateTimeImmutable $now, array &$state): array
    {
        $this->explorer?->explore();
        $ts = (float) $now->format('U.u');
        $state['startedAt'] ??= $ts;
        $ran = [];

        foreach ($this->registry->getCronJobs() as $name => $job) {
            $local = $job['timeZone'] !== null ? $now->setTimezone(new DateTimeZone($job['timeZone'])) : $now;
            $slot = $local->format($this->parser->parse($job['expression'])['hasSeconds'] ? 'Y-m-d H:i:s' : 'Y-m-d H:i');
            if (($state['cron'][$name] ?? null) === $slot || ! $this->parser->isDue($job['expression'], $local)) {
                continue;
            }
            $state['cron'][$name] = $slot;
            $this->invoke('cron', $name, $job['callable'], $now);
            $ran[] = $name;
        }

        foreach ($this->registry->getIntervals() as $name => $interval) {
            $last = $state['intervals'][$name] ?? $state['startedAt'];
            if ((int) round(($ts - $last) * 1000) < $interval['milliseconds']) {
                continue;
            }
            $state['intervals'][$name] = $ts;
            $this->invoke('interval', $name, $interval['callable'], $now);
            $ran[] = $name;
        }

        foreach ($this->registry->getTimeouts() as $name => $timeout) {
            if (isset($state['timeouts'][$name]) || (int) round(($ts - $state['startedAt']) * 1000) < $timeout['milliseconds']) {
                continue;
            }
            $state['timeouts'][$name] = $ts;
            $this->invoke('timeout', $name, $timeout['callable'], $now);
            $ran[] = $name;
        }

        return $ran;
    }

    private function invoke(string $type, string $name, callable $callable, DateTimeImmutable $now): void
    {
        $this->registry->markRun($type, $name, $now);
        try {
            $callable();
            unset($this->errors[$name]);
        } catch (\Throwable $e) {
            $this->errors[$name] = $e;
            ($this->logger ?? new \Nesto\Logger\Logger('Scheduler'))->error("Scheduled $type \"$name\" failed: {$e->getMessage()}", ['exception' => $e, 'context' => 'Scheduler']);
        }
    }
}
