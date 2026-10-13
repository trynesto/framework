<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;

/**
 * `php nesto migration:run` (alias `migrate`) runs pending migrations — TypeORM's migration:run.
 *
 *   php nesto migration:run                    # transaction mode from migrationsTransactionMode (default all)
 *   php nesto migration:run --transaction=each # or none
 *   php nesto migration:run --fake             # mark pending migrations as run without running them
 */
final class MigrateCommand extends Command
{
    use InteractsWithPackages;

    protected string $signature = 'migrate|migration:run {--dry-run : Only list what would run} {--t|transaction= : all, each or none} {--f|fake : Record the pending migrations as run without running them}';
    protected string $description = 'Run pending database migrations';

    public function needsApp(): bool
    {
        return true;
    }

    public function handle(): int
    {
        $ds = $this->package('Nesto\Orm\DataSource', 'ORM', 'OrmModule::forRoot([...])');
        if ($ds === null) {
            return self::FAILURE;
        }
        $migrator = $this->migrator($ds);
        $this->info('Running migrations (' . $this->migrationsDir() . ')');
        try {
            if ($this->option('dry-run')) {
                $pending = [];
                foreach ($migrator !== null && method_exists($migrator, 'status') ? $migrator->status() : [] as $row) {
                    if (empty($row['ran'])) {
                        $pending[] = $row['name'];
                    }
                }
                $this->printResult($pending, 'Pending');

                return self::SUCCESS;
            }
            $args = ['transaction' => $this->option('transaction') ?: null, 'fake' => (bool) $this->option('fake')];
            $result = $migrator !== null && method_exists($migrator, 'run')
                ? $this->callWithKnownArgs($migrator, 'run', $args)
                : $this->callWithKnownArgs($ds, 'runMigrations', $args);
        } catch (\Throwable $e) {
            $this->error('Migration failed: ' . $e->getMessage());

            return self::FAILURE;
        }
        $this->printResult($result, $this->option('fake') ? 'Faked' : 'Migrated');

        return self::SUCCESS;
    }

    private function printResult(mixed $result, string $verb): void
    {
        if (is_iterable($result)) {
            $count = 0;
            foreach ($result as $item) {
                $count++;
                $this->line('  ' . $this->output->color($verb, 'green') . ' ' . (is_object($item) ? $item::class : (string) $item));
            }
            $this->success($count === 0 ? 'Nothing to migrate.' : "$count migration(s).");

            return;
        }
        $this->success('Done.');
    }
}
