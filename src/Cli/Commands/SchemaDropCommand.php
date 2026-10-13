<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;

/**
 * `php nesto schema:drop --force` — TypeORM's schema:drop: drops the entity tables, join tables and the
 * migrations table (PostgreSQL native enum types too). Requires --force.
 */
final class SchemaDropCommand extends Command
{
    use InteractsWithPackages;

    protected string $signature = 'schema:drop {--force : Required: this deletes the tables and their data}';
    protected string $description = 'Drop every table of the entities (and the migrations table)';

    public function needsApp(): bool
    {
        return true;
    }

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('schema:drop deletes the entity tables and all their data: run it again with --force.');

            return self::FAILURE;
        }
        $ds = $this->package('Nesto\Orm\DataSource', 'ORM', 'OrmModule::forRoot([...])');
        if ($ds === null) {
            return self::FAILURE;
        }
        try {
            $ds->dropDatabase();
        } catch (\Throwable $e) {
            $this->error('Schema drop failed: ' . $e->getMessage());

            return self::FAILURE;
        }
        $this->success('Database schema dropped.');

        return self::SUCCESS;
    }
}
