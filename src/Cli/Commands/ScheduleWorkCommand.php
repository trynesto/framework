<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;

/** `php nesto schedule:work` keeps the process alive and runs due tasks as they come due (Scheduler::run(), ticks every second). */
final class ScheduleWorkCommand extends Command
{
    use InteractsWithPackages;
    use InteractsWithScheduler;

    protected string $signature = 'schedule:work';
    protected string $description = 'Run the scheduler in the foreground';

    public function needsApp(): bool
    {
        return true;
    }

    public function handle(): int
    {
        $scheduler = $this->package('Nesto\Schedule\Scheduler', 'Schedule', 'ScheduleModule::forRoot()');
        if ($scheduler === null) {
            return self::FAILURE;
        }
        $method = $this->firstExistingMethod($scheduler, 'run', 'work', 'start');
        if ($method === null) {
            $this->error('Scheduler has no run() method.');

            return self::FAILURE;
        }
        $this->listJobs($scheduler);
        $this->info('Scheduler running... (Ctrl+C to stop)');
        try {
            $this->callWithKnownArgs($scheduler, $method, ['output' => $this->output, 'onRun' => $this->reportRun(...)]);
        } catch (\Throwable $e) {
            $this->error('Scheduler stopped: ' . $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
