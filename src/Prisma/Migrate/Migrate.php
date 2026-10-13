<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

use Nesto\Orm\Connection;
use Nesto\Orm\Migration\Schema;
use Nesto\Prisma\PrismaException;
use Nesto\Prisma\PrismaProject;

/**
 * Prisma Migrate: `migrate dev`, `deploy`, `reset`, `status` and `resolve` over prisma/migrations and the
 * `_prisma_migrations` table.
 *
 *   $migrate = new Migrate($project, $reporter);
 *   $migrate->dev('add_posts');           // replay history on a shadow database, detect drift, write + apply the new migration
 *   $migrate->deploy();                   // apply pending migrations (production)
 *   $migrate->status();                   // MigrationStatus
 *   $migrate->resolveApplied('2024..._init');   $migrate->resolveRolledBack('2024..._x');
 *   $migrate->reset();                    // drop everything, re-apply every migration
 */
final class Migrate
{
    public function __construct(private readonly PrismaProject $project, private readonly Reporter $reporter) {}

    public function dev(?string $name = null, bool $createOnly = false): DevResult
    {
        $document = $this->project->document();
        $datasource = $document->requireDatasource();
        $directory = $this->project->migrations();
        $directory->assertProvider($datasource->provider);
        $url = $this->project->databaseUrl();
        $connection = $this->connect($url);
        $local = $directory->migrations();
        $records = (new MigrationsTable($connection))->records();
        $result = new DevResult();

        $applied = [];
        foreach ($records as $record) {
            if ($record->isApplied()) {
                $applied[$record->name] = $record;
            }
        }
        $localNames = array_map(fn (LocalMigration $m) => $m->name, $local);
        $reasons = [];
        foreach ($records as $record) {
            if ($record->isFailed()) {
                $reasons[] = sprintf('The migration `%s` failed to apply (started at %s).', $record->name, $record->startedAt);
            }
        }
        $missing = array_values(array_diff(array_keys($applied), $localNames));
        if ($missing !== []) {
            $reasons[] = "The following migration(s) are applied to the database but missing from the local migrations directory: " . implode(', ', $missing);
        }
        foreach ($local as $migration) {
            if (isset($applied[$migration->name]) && $applied[$migration->name]->checksum !== $migration->checksum()) {
                $reasons[] = sprintf('The migration `%s` was modified after it was applied.', $migration->name);
            }
        }

        $shadow = ShadowDatabase::create($url, $this->project->shadowDatabaseUrl());
        try {
            $shadowConnection = $shadow->connection();
            $shadowRunner = new MigrationRunner($shadowConnection);
            $appliedLocal = array_values(array_filter($local, fn (LocalMigration $m) => isset($applied[$m->name])));
            foreach ($appliedLocal as $migration) {
                $this->replay($shadowRunner, $migration);
            }
            if ($reasons === []) {
                $drift = (new Differ($shadowConnection))->plan(DatabaseSchema::introspect($shadowConnection), DatabaseSchema::introspect($connection));
                if (! $drift->isEmpty()) {
                    $reasons[] = "Drift detected: Your database schema is not in sync with your migration history.\n\n"
                        . "The following is a summary of the differences between the expected database schema given your migrations files, and the actual schema of the database.\n\n"
                        . DiffSummary::describe($drift);
                }
            }
            foreach ($local as $migration) {
                if (! isset($applied[$migration->name])) {
                    $this->replay($shadowRunner, $migration);
                }
            }

            if ($reasons !== []) {
                foreach ($reasons as $reason) {
                    $this->reporter->warn($reason);
                }
                $question = sprintf('We need to reset the %s at "%s"', $this->describeTarget($url), $url->location());
                if (! $this->reporter->interactive()) {
                    throw new PrismaException($question . ".\nPrisma Migrate has detected that the environment is non-interactive. Run `prisma:migrate reset` to reset the database (all data will be lost).");
                }
                if (! $this->reporter->confirm($question . "\nDo you want to continue? All data will be lost.", false)) {
                    throw new PrismaException('Reset cancelled.');
                }
                DatabaseReset::reset($connection);
                $result->reset = true;
                $applied = [];
            }

            $runner = new MigrationRunner($connection);
            foreach ($local as $migration) {
                if (! isset($applied[$migration->name])) {
                    $this->reporter->info(sprintf('Applying migration `%s`', $migration->name));
                    $runner->apply($migration);
                    $result->applied[] = $migration->name;
                }
            }

            $target = SchemaTranslator::translate($document);
            $plan = (new Differ($shadowConnection))->plan(DatabaseSchema::introspect($shadowConnection), $target);
            if ($plan->isEmpty()) {
                $this->reporter->info($result->applied !== [] || $result->reset
                    ? 'Your database is now in sync with your schema.'
                    : 'Already in sync, no schema change or pending migration was found.');

                return $result;
            }
            $statements = $plan->statements(new Schema($shadowConnection));
            $headerWarnings = $plan->warnings($shadowConnection, false);
            if (! $createOnly) {
                $live = (new Differ($connection))->plan(DatabaseSchema::introspect($connection), $target)->warnings($connection, true);
                $unexecutable = array_values(array_filter($live, fn (MigrationWarning $w) => $w->unexecutable));
                if ($unexecutable !== []) {
                    throw new PrismaException("We found changes that cannot be executed:\n\n" . implode("\n", array_map(fn ($w) => '  - ' . $w->message, $unexecutable))
                        . "\n\nYou can use `prisma:migrate dev --create-only` to create the migration file, and manually modify it to address the underlying issue(s).\nThen run `prisma:migrate dev` to apply it and verify it works.");
                }
                if ($live !== []) {
                    $this->reporter->warn("Warnings for the current datasource:\n\n" . implode("\n", array_map(fn ($w) => '  - ' . $w->message, $live)));
                    if (! $this->reporter->confirm('Are you sure you want to create and apply this migration?', false)) {
                        throw new PrismaException('Migration cancelled.');
                    }
                }
            }
            if ($name === null) {
                $name = $this->reporter->interactive() ? (string) $this->reporter->ask('Enter a name for the new migration:', '') : '';
            }
            $lockProvider = $datasource->provider;
            $migration = $directory->create($name, SqlScript::render($statements, $headerWarnings, $datasource->provider), $lockProvider);
            $result->created = $migration;
            if ($createOnly) {
                $this->reporter->info(sprintf("Prisma Migrate created the following migration without applying it %s\n\nYou can now edit it and apply it by running prisma:migrate dev.", $migration->name));

                return $result;
            }
            $this->reporter->info(sprintf("Applying migration `%s`\n\nThe following migration(s) have been created and applied from new schema changes:\n\nmigrations/\n  └─ %s/\n    └─ migration.sql", $migration->name, $migration->name));
            $runner->apply($migration);
            $result->applied[] = $migration->name;
            $this->reporter->info('Your database is now in sync with your schema.');

            return $result;
        } finally {
            $shadow->drop();
        }
    }

    /** Apply pending migrations. @return list<string> the applied migration names */
    public function deploy(): array
    {
        $datasource = $this->project->datasource();
        $directory = $this->project->migrations();
        $directory->assertProvider($datasource->provider);
        $connection = $this->connect($this->project->databaseUrl());
        $records = (new MigrationsTable($connection))->records();
        foreach ($records as $record) {
            if ($record->isFailed()) {
                throw new PrismaException(sprintf(
                    "migrate found failed migrations in the target database, new migrations will not be applied. Read more about how to resolve migration issues in a production database: https://pris.ly/d/migrate-resolve\nThe `%s` migration started at %s failed",
                    $record->name,
                    $record->startedAt,
                ), 3009);
            }
        }
        $applied = [];
        foreach ($records as $record) {
            if ($record->isApplied()) {
                $applied[$record->name] = true;
            }
        }
        $local = $directory->migrations();
        $this->reporter->info(sprintf('%d migration%s found in %s', count($local), count($local) === 1 ? '' : 's', $this->project->relative($directory->path)));
        $runner = new MigrationRunner($connection);
        $done = [];
        foreach ($local as $migration) {
            if (isset($applied[$migration->name])) {
                continue;
            }
            $this->reporter->info(sprintf('Applying migration `%s`', $migration->name));
            $runner->apply($migration);
            $done[] = $migration->name;
        }
        if ($done === []) {
            $this->reporter->info('No pending migrations to apply.');
        } else {
            $this->reporter->info("The following migration(s) have been applied:\n\nmigrations/\n" . implode("\n", array_map(fn (string $n) => "  └─ $n/\n    └─ migration.sql", $done)) . "\n\nAll migrations have been successfully applied.");
        }

        return $done;
    }

    /** Drop everything and apply every migration again. @return list<string> applied migration names */
    public function reset(): array
    {
        $datasource = $this->project->datasource();
        $directory = $this->project->migrations();
        $directory->assertProvider($datasource->provider);
        $connection = $this->connect($this->project->databaseUrl());
        DatabaseReset::reset($connection);
        $runner = new MigrationRunner($connection);
        $done = [];
        foreach ($directory->migrations() as $migration) {
            $this->reporter->info(sprintf('Applying migration `%s`', $migration->name));
            $runner->apply($migration);
            $done[] = $migration->name;
        }

        return $done;
    }

    public function status(): MigrationStatus
    {
        $directory = $this->project->migrations();
        $connection = $this->project->connect();
        $local = $directory->migrations();
        $records = (new MigrationsTable($connection))->records();
        $status = new MigrationStatus();
        $status->local = array_map(fn (LocalMigration $m) => $m->name, $local);
        $applied = [];
        foreach ($records as $record) {
            if ($record->isApplied()) {
                $applied[$record->name] = true;
            }
            if ($record->isFailed()) {
                $status->failed[] = $record->name;
            }
        }
        $status->applied = array_keys($applied);
        $status->pending = array_values(array_filter($status->local, fn (string $n) => ! isset($applied[$n]) && ! in_array($n, $status->failed, true)));
        $status->missingLocally = array_values(array_diff($status->applied, $status->local));

        return $status;
    }

    public function resolveApplied(string $name): void
    {
        $migration = $this->project->migrations()->find($name)
            ?? throw new PrismaException(sprintf('P3017: The migration %s could not be found. Please make sure that the migration exists, and that you included the whole name of the directory. (example: "20201207184859_initial_migration")', $name));
        $table = new MigrationsTable($this->project->connect());
        foreach ($table->records() as $record) {
            if ($record->name === $name && $record->isApplied()) {
                throw new PrismaException(sprintf('P3008: The migration `%s` is already recorded as applied in the database.', $name));
            }
        }
        $table->markApplied($name, $migration->checksum());
        $this->reporter->info(sprintf('Migration %s marked as applied.', $name));
    }

    public function resolveRolledBack(string $name): void
    {
        $table = new MigrationsTable($this->project->connect());
        $records = array_values(array_filter($table->records(), fn (MigrationRecord $r) => $r->name === $name));
        if ($records === []) {
            throw new PrismaException(sprintf('P3011: Migration `%s` cannot be rolled back because it was never applied to the database. Hint: did you pass in the whole migration name? (example: "20201207184859_initial_migration")', $name));
        }
        if ($table->markRolledBack($name) === 0) {
            throw new PrismaException(sprintf('P3012: Migration `%s` cannot be rolled back because it is not in a failed state.', $name));
        }
        $this->reporter->info(sprintf('Migration %s marked as rolled back.', $name));
    }

    private function replay(MigrationRunner $runner, LocalMigration $migration): void
    {
        try {
            $runner->apply($migration, false);
        } catch (PrismaException $e) {
            throw new PrismaException(sprintf(
                "Migration `%s` failed to apply cleanly to the shadow database.\nError:\n%s",
                $migration->name,
                $e->getPrevious()?->getMessage() ?? $e->getMessage(),
            ), 3006, $e);
        }
    }

    private function connect(DatabaseUrl $url): Connection
    {
        return $this->project->connect(true, fn (string $database) => $this->reporter->info(sprintf('%s database %s created at %s', match ($url->provider) {
            'postgresql' => 'PostgreSQL',
            'mysql' => 'MySQL',
            default => 'SQLite',
        }, $database, $url->location())));
    }

    private function describeTarget(DatabaseUrl $url): string
    {
        return match ($url->provider) {
            'postgresql' => sprintf('"%s" schema', $url->schema()),
            'mysql' => sprintf('MySQL database "%s"', $url->database()),
            default => sprintf('SQLite database "%s"', basename($url->database())),
        };
    }
}
