<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;

/** `php nesto queue:work --queue=default --sleep=1` runs Nesto\Queue\Worker::work(). */
final class QueueWorkCommand extends Command
{
    use InteractsWithPackages;

    protected string $signature = 'queue:work {--queue= : Queue name(s), comma separated} {--once : Process a single job then exit} {--sleep=1 : Seconds to sleep when the queue is empty} {--max-jobs= : Stop after this many jobs}';
    protected string $description = 'Process jobs from the queue';

    public function needsApp(): bool
    {
        return true;
    }

    public function handle(): int
    {
        $worker = $this->package('Nesto\Queue\Worker', 'Queue', 'QueueModule::forRoot([...])');
        if ($worker === null) {
            return self::FAILURE;
        }
        $queue = (string) ($this->option('queue') ?? '');
        $queues = $queue !== '' ? array_values(array_filter(array_map('trim', explode(',', $queue)))) : [];
        $opts = ['once' => (bool) $this->option('once'), 'sleep' => (float) $this->option('sleep')];
        if ($this->option('max-jobs') !== null && $this->option('max-jobs') !== '') {
            $opts['maxJobs'] = (int) $this->option('max-jobs');
        }
        $this->info('Processing jobs' . ($queues !== [] ? ' from [' . implode(', ', $queues) . ']' : '') . '... (Ctrl+C to stop)');
        try {
            $this->callWithKnownArgs($worker, 'work', ['queues' => $queues, 'queue' => $queues[0] ?? null, 'opts' => $opts, 'options' => $opts] + $opts);
        } catch (\Throwable $e) {
            $this->error('Worker stopped: ' . $e->getMessage());

            return self::FAILURE;
        }
        $processed = method_exists($worker, 'processed') ? $worker->processed() : null;
        $this->success($processed !== null ? "Processed $processed job(s)." : 'Worker finished.');

        return self::SUCCESS;
    }
}
