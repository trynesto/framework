<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\CliException;
use Nesto\Cli\Command;
use Nesto\Cli\Generator\Str;
use Nesto\Cli\Generator\Stub;

/**
 * `php nesto migration:create add_nickname` (alias `migrate:generate`) writes a new migration class
 * to fill in by hand — TypeORM's migration:create. Names like `create_cats_table` get a createTable()
 * scaffold. To build a migration from the entities, use `migration:generate`.
 */
final class MigrationCreateCommand extends Command
{
    use InteractsWithPackages;
    use WritesMigrations;

    protected string $signature = 'migrate:generate|migration:create {name : Migration name, e.g. add_nickname_to_users}';
    protected string $description = 'Create a new empty migration file';

    public function handle(): int
    {
        $name = Str::snake((string) $this->argument('name'));
        if ($name === '') {
            throw new CliException('A migration name is required.');
        }
        [$file, $class] = $this->migrationTarget($name);
        $table = preg_match('/^create_(.+?)_table$/', $name, $m) === 1 ? $m[1] : null;

        file_put_contents($file, Stub::render($table !== null ? 'migration' : 'migration-empty', [
            'class' => $class,
            'name' => $name,
            'table' => (string) $table,
            'date' => date('Y-m-d H:i:s'),
        ]));
        $this->line($this->output->color('CREATE', 'green') . ' ' . $this->relative($file));
        $this->comment("Run \"{$this->bin()} migration:run\" to apply it.");

        return self::SUCCESS;
    }
}
