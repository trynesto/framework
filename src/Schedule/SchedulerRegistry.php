<?php

declare(strict_types=1);

namespace Nesto\Schedule;

use DateTimeImmutable;

/**
 * Holds every cron job, interval and timeout known to the scheduler (Nest's SchedulerRegistry).
 * Populated by ScheduleExplorer from #[Cron] / #[Interval] / #[Timeout] and by hand:
 *
 *   $registry->addCronJob('report', CronExpression::EVERY_HOUR, fn () => $this->report(), timeZone: 'UTC');
 *   $registry->addInterval('poll', 5000, fn () => $this->poll());
 *   $registry->addTimeout('warmup', 1000, fn () => $this->warm());
 *   $registry->getCronJobs();        // name => ['expression' => ..., 'callable' => ..., 'timeZone' => ..., 'lastRun' => ?DateTimeImmutable]
 *   $registry->deleteCronJob('report');
 */
final class SchedulerRegistry
{
    /** @var array<string, array{expression: string, callable: callable, timeZone: ?string, lastRun: ?DateTimeImmutable, runs: int}> */
    private array $cronJobs = [];
    /** @var array<string, array{milliseconds: int, callable: callable, lastRun: ?DateTimeImmutable, runs: int}> */
    private array $intervals = [];
    /** @var array<string, array{milliseconds: int, callable: callable, lastRun: ?DateTimeImmutable, runs: int}> */
    private array $timeouts = [];

    // ---- cron -------------------------------------------------------------------------------

    public function addCronJob(string $name, string $expression, callable $callable, ?string $timeZone = null): void
    {
        $this->assertNew($this->cronJobs, 'Cron job', $name);
        (new CronParser)->parse($expression);
        if ($timeZone !== null && ! in_array($timeZone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new \InvalidArgumentException("Unknown time zone \"$timeZone\" for cron job \"$name\".");
        }
        $this->cronJobs[$name] = ['expression' => $expression, 'callable' => $callable, 'timeZone' => $timeZone, 'lastRun' => null, 'runs' => 0];
    }

    /** @return array<string, array{expression: string, callable: callable, timeZone: ?string, lastRun: ?DateTimeImmutable, runs: int}> */
    public function getCronJobs(): array
    {
        return $this->cronJobs;
    }

    /** @return array{expression: string, callable: callable, timeZone: ?string, lastRun: ?DateTimeImmutable, runs: int} */
    public function getCronJob(string $name): array
    {
        return $this->cronJobs[$name] ?? throw new \InvalidArgumentException("No cron job named \"$name\" exists.");
    }

    public function deleteCronJob(string $name): void
    {
        unset($this->cronJobs[$name]);
    }

    // ---- intervals ------------------------------------------------------------------------

    public function addInterval(string $name, int $milliseconds, callable $callable): void
    {
        $this->assertNew($this->intervals, 'Interval', $name);
        $this->intervals[$name] = ['milliseconds' => max(1, $milliseconds), 'callable' => $callable, 'lastRun' => null, 'runs' => 0];
    }

    /** @return array<string, array{milliseconds: int, callable: callable, lastRun: ?DateTimeImmutable, runs: int}> */
    public function getIntervals(): array
    {
        return $this->intervals;
    }

    /** @return array{milliseconds: int, callable: callable, lastRun: ?DateTimeImmutable, runs: int} */
    public function getInterval(string $name): array
    {
        return $this->intervals[$name] ?? throw new \InvalidArgumentException("No interval named \"$name\" exists.");
    }

    public function deleteInterval(string $name): void
    {
        unset($this->intervals[$name]);
    }

    // ---- timeouts --------------------------------------------------------------------------

    public function addTimeout(string $name, int $milliseconds, callable $callable): void
    {
        $this->assertNew($this->timeouts, 'Timeout', $name);
        $this->timeouts[$name] = ['milliseconds' => max(0, $milliseconds), 'callable' => $callable, 'lastRun' => null, 'runs' => 0];
    }

    /** @return array<string, array{milliseconds: int, callable: callable, lastRun: ?DateTimeImmutable, runs: int}> */
    public function getTimeouts(): array
    {
        return $this->timeouts;
    }

    /** @return array{milliseconds: int, callable: callable, lastRun: ?DateTimeImmutable, runs: int} */
    public function getTimeout(string $name): array
    {
        return $this->timeouts[$name] ?? throw new \InvalidArgumentException("No timeout named \"$name\" exists.");
    }

    public function deleteTimeout(string $name): void
    {
        unset($this->timeouts[$name]);
    }

    // ---- misc -------------------------------------------------------------------------------

    /** @param 'cron'|'interval'|'timeout' $type */
    public function doesExist(string $type, string $name): bool
    {
        return match ($type) {
            'cron' => isset($this->cronJobs[$name]),
            'interval' => isset($this->intervals[$name]),
            'timeout' => isset($this->timeouts[$name]),
            default => false,
        };
    }

    /** @internal used by Scheduler after a run */
    public function markRun(string $type, string $name, DateTimeImmutable $at): void
    {
        $prop = match ($type) {
            'cron' => 'cronJobs',
            'interval' => 'intervals',
            'timeout' => 'timeouts',
            default => throw new \InvalidArgumentException("Unknown schedule type \"$type\"."),
        };
        if (isset($this->{$prop}[$name])) {
            $this->{$prop}[$name]['lastRun'] = $at;
            $this->{$prop}[$name]['runs']++;
        }
    }

    private function assertNew(array $list, string $kind, string $name): void
    {
        if (isset($list[$name])) {
            throw new \InvalidArgumentException("$kind with the given name (\"$name\") already exists. Ignored.");
        }
    }
}
