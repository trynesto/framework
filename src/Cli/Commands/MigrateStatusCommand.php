<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;

/** `php nesto migration:show` (alias `migrate:status`) lists executed / pending migrations — TypeORM's migration:show. */
final class MigrateStatusCommand extends Command
{
    use InteractsWithPackages;

    protected string $signature = 'migrate:status|migration:show';
    protected string $description = 'Show the status of each migration';

    public function needsApp(): bool
    {
        return true;
    }

    public function handle(): int
    {
        $dir = $this->migrationsDir();
        $ds = $this->package('Nesto\Orm\DataSource', 'ORM', 'OrmModule::forRoot([...])');
        if ($ds === null) {
            $files = is_dir($dir) ? (glob($dir . '/*.php') ?: []) : [];
            if ($files !== []) {
                $this->table(['Migration file', 'Status'], array_map(fn ($f) => [basename($f, '.php'), '?'], $files));
            }

            return self::FAILURE;
        }
        $migrator = $this->migrator($ds);
        try {
            $rows = $migrator !== null && method_exists($migrator, 'status')
                ? $migrator->status()
                : $this->callWithKnownArgs($ds, 'showMigrations');
        } catch (\Throwable $e) {
            $this->error('Could not read the migration status: ' . $e->getMessage());

            return self::FAILURE;
        }
        if (! is_iterable($rows) || $rows === []) {
            $this->warn("No migrations found (directory: $dir).");

            return self::SUCCESS;
        }
        $table = [];
        $pending = 0;
        foreach ($rows as $row) {
            $ran = (bool) ($row['ran'] ?? false);
            $pending += $ran ? 0 : 1;
            $table[] = [
                (string) ($row['name'] ?? '?'),
                $ran ? $this->output->color('Ran', 'green') : $this->output->color('Pending', 'yellow'),
                isset($row['batch']) ? (string) $row['batch'] : '',
            ];
        }
        $this->table(['Migration', 'Status', 'Batch'], $table);
        $this->comment("$pending pending");

        return self::SUCCESS;
    }
}
