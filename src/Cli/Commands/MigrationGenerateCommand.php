<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\CliException;
use Nesto\Cli\Command;
use Nesto\Cli\Generator\Str;

/**
 * `php nesto migration:generate add_nickname` — TypeORM's migration:generate: compares the entities
 * with the database and writes a migration whose up() applies the difference and down() reverts it
 * (Schema / Blueprint calls).
 *
 *   php nesto migration:generate add_nickname
 *   php nesto migration:generate add_nickname --dry-run   # print it instead of writing it
 *   php nesto migration:generate check --check            # CI: exit 1 when entities and database differ
 */
final class MigrationGenerateCommand extends Command
{
    use InteractsWithPackages;
    use WritesMigrations;

    protected string $signature = 'migration:generate {name : Migration name, e.g. add_nickname_to_users}'
        . ' {--dry-run : Print the migration instead of writing it}'
        . ' {--check : Exit with an error when the schema differs from the entities, write nothing}';
    protected string $description = 'Generate a migration from the differences between the entities and the database';

    public function needsApp(): bool
    {
        return true;
    }

    public function handle(): int
    {
        $name = Str::snake((string) $this->argument('name'));
        if ($name === '') {
            throw new CliException('A migration name is required.');
        }
        $ds = $this->package('Nesto\Orm\DataSource', 'ORM', 'OrmModule::forRoot([...])');
        if ($ds === null) {
            return self::FAILURE;
        }
        $timestamp = date('YmdHis');
        $class = Str::pascal($name) . $timestamp;
        try {
            $code = $ds->generateMigration($class);
        } catch (\Throwable $e) {
            $this->error('Could not compare the entities with the database: ' . $e->getMessage());

            return self::FAILURE;
        }
        if ($code === null) {
            $this->success('No changes in the database schema were found: nothing to generate.');

            return self::SUCCESS;
        }
        if ($this->option('check')) {
            $this->error('The database schema differs from the entities. Run "' . $this->bin() . ' migration:generate <name>" to create a migration.');

            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $this->line($code);

            return self::SUCCESS;
        }
        [$file, $class] = $this->migrationTarget($name);
        file_put_contents($file, str_replace(Str::pascal($name) . $timestamp, $class, $code));
        $this->line($this->output->color('CREATE', 'green') . ' ' . $this->relative($file));
        $this->comment("Review it, then run \"{$this->bin()} migration:run\".");

        return self::SUCCESS;
    }
}
