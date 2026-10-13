<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;

/** `php nesto migration:revert` (alias `migrate:revert`) reverts the last executed migration — TypeORM's migration:revert. */
final class MigrateRevertCommand extends Command
{
    use InteractsWithPackages;

    protected string $signature = 'migrate:revert|migration:revert {--steps=1 : How many migrations to revert} {--t|transaction= : all, each or none} {--f|fake : Remove the record without running down()}';
    protected string $description = 'Revert the last database migration';

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
        $steps = max(1, (int) $this->option('steps'));
        try {
            for ($i = 0; $i < $steps; $i++) {
                $args = ['transaction' => $this->option('transaction') ?: null, 'fake' => (bool) $this->option('fake')];
                $result = $migrator !== null && method_exists($migrator, 'revert')
                    ? $this->callWithKnownArgs($migrator, 'revert', $args)
                    : $this->callWithKnownArgs($ds, 'revertLastMigration', $args);
                if ($result === null) {
                    $this->comment('Nothing to revert.');
                    break;
                }
                $this->line('  ' . $this->output->color('Reverted', 'yellow') . ' ' . (is_scalar($result) ? (string) $result : (is_object($result) ? $result::class : json_encode($result))));
            }
        } catch (\Throwable $e) {
            $this->error('Revert failed: ' . $e->getMessage());

            return self::FAILURE;
        }
        $this->success('Done.');

        return self::SUCCESS;
    }
}
