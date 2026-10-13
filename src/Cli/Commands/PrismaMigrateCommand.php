<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cli\Command;
use Nesto\Prisma\Migrate\Migrate;
use Nesto\Prisma\Migrate\MigrateDiff;

/**
 * `php nesto prisma:migrate dev|deploy|reset|status|resolve|diff` — Prisma Migrate.
 *
 *   prisma:migrate dev [--name=init] [--create-only] [--skip-generate] [--skip-seed]
 *   prisma:migrate deploy
 *   prisma:migrate reset [--force] [--skip-seed] [--skip-generate]
 *   prisma:migrate status
 *   prisma:migrate resolve --applied=<migration> | --rolled-back=<migration>
 *   prisma:migrate diff --from-... --to-... [--script] [--exit-code] [--output=file]
 */
final class PrismaMigrateCommand extends Command
{
    use InteractsWithPrisma;

    private const SOURCES = ['empty', 'schema-datamodel', 'schema-datasource', 'migrations', 'url'];

    protected string $signature = 'prisma:migrate {action : dev, deploy, reset, status, resolve or diff}'
        . ' {--schema= : Path to the schema file}'
        . ' {--name= : dev: name of the new migration}'
        . ' {--create-only : dev: create the migration without applying it}'
        . ' {--skip-generate : do not run prisma:generate}'
        . ' {--skip-seed : dev / reset: do not run the seed script}'
        . ' {--force : reset: skip the confirmation}'
        . ' {--applied= : resolve: record a migration as applied}'
        . ' {--rolled-back= : resolve: record a failed migration as rolled back}'
        . ' {--from-empty} {--to-empty}'
        . ' {--from-schema-datamodel=} {--to-schema-datamodel=}'
        . ' {--from-schema-datasource=} {--to-schema-datasource=}'
        . ' {--from-migrations=} {--to-migrations=}'
        . ' {--from-url=} {--to-url=}'
        . ' {--shadow-database-url= : diff: server for temporary databases}'
        . ' {--script : diff: output SQL instead of a summary}'
        . ' {--exit-code : diff: exit 2 when there is a difference, 0 when there is none}'
        . ' {--o|output= : diff: write to this file instead of the console}';
    protected string $description = 'Create and apply migrations (Prisma Migrate)';
    protected string $help = <<<'TXT'
          {bin} prisma:migrate dev --name init        create a migration from schema changes and apply it (development)
          {bin} prisma:migrate dev --create-only      create it without applying (edit it first)
          {bin} prisma:migrate deploy                 apply pending migrations (production / CI)
          {bin} prisma:migrate status                 compare prisma/migrations with the database
          {bin} prisma:migrate reset --force          drop the database, re-apply every migration, seed
          {bin} prisma:migrate resolve --applied 20241013093000_init
          {bin} prisma:migrate resolve --rolled-back 20241013093000_init
          {bin} prisma:migrate diff --from-empty --to-schema-datamodel prisma/schema.prisma --script
        TXT;

    public function handle(): int
    {
        return match ((string) $this->argument('action')) {
            'dev' => $this->prisma(fn () => $this->dev()),
            'deploy' => $this->prisma(function (): int {
                (new Migrate($this->prismaProject(), $this->reporter()))->deploy();

                return self::SUCCESS;
            }),
            'reset' => $this->prisma(fn () => $this->reset()),
            'status' => $this->prisma(fn () => $this->status()),
            'resolve' => $this->prisma(fn () => $this->resolve()),
            'diff' => $this->prisma(fn () => $this->diff()),
            default => $this->unknownAction(),
        };
    }

    private function dev(): int
    {
        $project = $this->prismaProject();
        $name = $this->option('name');
        $result = (new Migrate($project, $this->reporter()))->dev(is_string($name) ? $name : null, (bool) $this->option('create-only'));
        if (! $this->option('create-only')) {
            $this->runGenerate($project);
        }
        if ($result->reset && ! $this->option('skip-seed')) {
            return $this->runSeed($project) ? self::SUCCESS : self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function reset(): int
    {
        $project = $this->prismaProject();
        $url = $project->databaseUrl();
        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('Use the --force flag to use the reset command in an unnamed environment like non-interactive shells');

                return self::FAILURE;
            }
            if (! $this->confirm(sprintf('Are you sure you want to reset your database at "%s"? All data will be lost.', $url->location()), false)) {
                $this->line('Reset cancelled.');

                return self::FAILURE;
            }
        }
        (new Migrate($project, $this->reporter()))->reset();
        $this->success('Database reset successful');
        $this->runGenerate($project);
        if (! $this->option('skip-seed')) {
            return $this->runSeed($project) ? self::SUCCESS : self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function status(): int
    {
        $project = $this->prismaProject();
        $status = (new Migrate($project, $this->reporter()))->status();
        $folder = $project->relative($project->migrations()->path);
        if ($status->local === []) {
            $this->line("No migration found in $folder");
        } else {
            $this->line(sprintf('%d migration%s found in %s', count($status->local), count($status->local) === 1 ? '' : 's', $folder));
        }
        $this->newLine();
        if ($status->missingLocally !== []) {
            $this->warn("The following migration(s) are applied to the database but missing from the local migrations directory:\n" . implode("\n", $status->missingLocally));
        }
        if ($status->failed !== []) {
            $this->warn("Following migration have failed:\n" . implode("\n", $status->failed));
            $this->line('During development if the failed migration(s) have not been deployed to a production database you can then fix the migration(s) and run prisma:migrate dev.');
        }
        if ($status->pending !== []) {
            $this->warn(sprintf("Following migration%s have not yet been applied:\n%s", count($status->pending) === 1 ? '' : 's', implode("\n", $status->pending)));
            $this->line('To apply migrations in development run prisma:migrate dev.');
            $this->line('To apply migrations in production run prisma:migrate deploy.');
        }
        if ($status->isUpToDate()) {
            $this->success('Database schema is up to date!');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    private function resolve(): int
    {
        $applied = $this->option('applied');
        $rolledBack = $this->option('rolled-back');
        $migrate = new Migrate($this->prismaProject(), $this->reporter());
        if (is_string($applied) && $applied !== '') {
            $migrate->resolveApplied($applied);

            return self::SUCCESS;
        }
        if (is_string($rolledBack) && $rolledBack !== '') {
            $migrate->resolveRolledBack($rolledBack);

            return self::SUCCESS;
        }
        $this->error('--applied or --rolled-back must be part of the command like:' . "\n" . $this->bin() . ' prisma:migrate resolve --applied="20201231000000_example"');

        return self::FAILURE;
    }

    private function diff(): int
    {
        $from = $this->source('from');
        $to = $this->source('to');
        if ($from === null || $to === null) {
            $this->error(sprintf('%s source is required: use one of --%1$s-empty, --%1$s-schema-datamodel, --%1$s-schema-datasource, --%1$s-migrations, --%1$s-url.', $from === null ? 'from' : 'to'));

            return self::FAILURE;
        }
        $root = $this->context !== null ? $this->context->rootDir() : (string) getcwd();
        $shadow = $this->option('shadow-database-url');
        $result = (new MigrateDiff($root, is_string($shadow) && $shadow !== '' ? $shadow : null))->diff($from, $to);
        $text = $this->option('script') ? $result['sql'] : $result['summary'] . "\n";
        $file = $this->option('output');
        if (is_string($file) && $file !== '') {
            file_put_contents(str_starts_with($file, '/') ? $file : $root . '/' . $file, $text);
        } else {
            $this->output->write($text);
        }
        if ($this->option('exit-code')) {
            return $result['empty'] ? self::SUCCESS : 2;
        }

        return self::SUCCESS;
    }

    /** @return array{0: string, 1?: string}|null */
    private function source(string $side): ?array
    {
        $found = [];
        foreach (self::SOURCES as $kind) {
            $value = $this->option($side . '-' . $kind);
            if ($kind === 'empty' ? (bool) $value : (is_string($value) && $value !== '')) {
                $found[] = $kind === 'empty' ? ['empty'] : [$kind, (string) $value];
            }
        }
        if (count($found) > 1) {
            throw new \Nesto\Prisma\PrismaException(sprintf('Only one --%s-... source can be given.', $side));
        }

        return $found[0] ?? null;
    }

    private function unknownAction(): int
    {
        $this->error(sprintf('Unknown command "prisma:migrate %s". Use dev, deploy, reset, status, resolve or diff.', (string) $this->argument('action')));

        return self::FAILURE;
    }
}
