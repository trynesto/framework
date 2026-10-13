<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

/** Shared output of schedule:run / schedule:work: the registered jobs, and a line per job that ran. */
trait InteractsWithScheduler
{
    /** Prints the jobs table (or a warning when there is none). Returns false when nothing is scheduled. */
    private function listJobs(object $scheduler): bool
    {
        if (! method_exists($scheduler, 'jobs')) {
            return true;
        }
        $jobs = $scheduler->jobs();
        if ($jobs === []) {
            $this->warn('No scheduled jobs found. Add #[Cron], #[Interval] or #[Timeout] to a public method of a provider');
            $this->line('  or controller of a module that is imported (and import ScheduleModule in AppModule).');

            return false;
        }
        $this->output->table(['Type', 'Job', 'Schedule'], array_map(static fn (array $j) => [$j['type'], $j['name'], $j['schedule']], $jobs));

        return true;
    }

    /**
     * @param list<string> $ran
     * @param array<string, \Throwable> $errors
     */
    private function reportRun(array $ran, array $errors): void
    {
        $time = $this->output->color('[' . date('H:i:s') . ']', 'gray');
        foreach ($ran as $name) {
            $this->line(isset($errors[$name])
                ? "  $time " . $this->output->color('Failed', 'red') . " $name: " . $errors[$name]->getMessage()
                : "  $time " . $this->output->color('Ran', 'green') . " $name");
        }
    }
}
