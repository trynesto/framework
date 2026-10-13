<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;

/** `php nesto schema:log` — TypeORM's schema:log: prints the SQL `schema:sync` would run, changing nothing. */
final class SchemaLogCommand extends Command
{
    use InteractsWithPackages;

    protected string $signature = 'schema:log';
    protected string $description = 'Show the SQL schema:sync would run';

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
        try {
            $statements = $ds->logSchema();
        } catch (\Throwable $e) {
            $this->error('Could not compare the entities with the database: ' . $e->getMessage());

            return self::FAILURE;
        }
        if ($statements === []) {
            $this->success('Your schema is up to date: there are no queries to be executed by schema synchronization.');

            return self::SUCCESS;
        }
        $this->info(count($statements) . ' quer' . (count($statements) === 1 ? 'y' : 'ies') . ' would be executed by schema synchronization:');
        foreach ($statements as $sql) {
            $this->line(rtrim($sql, ';') . ';');
        }

        return self::SUCCESS;
    }
}
