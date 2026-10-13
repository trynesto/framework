<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;

/** `php nesto schedule:run` executes every task that is due right now (Scheduler::runDueOnce()). Put it in cron every minute. */
final class ScheduleRunCommand extends Command
{
    use InteractsWithPackages;
    use InteractsWithScheduler;

    protected string $signature = 'schedule:run';
    protected string $description = 'Run the scheduled tasks that are due now';

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
        $method = $this->firstExistingMethod($scheduler, 'runDueOnce', 'runDue', 'tick');
        if ($method === null) {
            $this->error('Scheduler has no runDueOnce() method.');

            return self::FAILURE;
        }
        try {
            $result = $this->callWithKnownArgs($scheduler, $method, ['output' => $this->output]);
        } catch (\Throwable $e) {
            $this->error('Scheduler failed: ' . $e->getMessage());

            return self::FAILURE;
        }
        if (is_array($result)) {
            $names = array_map(static fn ($task) => is_scalar($task) ? (string) $task : (string) json_encode($task), $result);
            $errors = method_exists($scheduler, 'errors') ? array_intersect_key($scheduler->errors(), array_flip($names)) : [];
            $this->reportRun($names, $errors);
            if ($result === [] && method_exists($scheduler, 'jobs') && $scheduler->jobs() === []) {
                $this->listJobs($scheduler);

                return self::SUCCESS;
            }
            $this->success(count($result) . ' task(s) executed' . ($errors === [] ? '.' : ', ' . count($errors) . ' failed.'));
        } else {
            $this->success('Due tasks executed.');
        }

        return self::SUCCESS;
    }
}
