<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

use Nesto\Orm\Migration\Schema;
use Nesto\Prisma\PrismaException;
use Nesto\Prisma\PrismaProject;

/**
 * `prisma db push`: make the database match the schema without migrations — the live schema is
 * introspected, diffed with the Comparator and the plan applied. Steps that lose data need
 * `acceptDataLoss` (or a confirmation in an interactive session); steps that cannot run on the current data
 * (a required column without default on a non-empty table, ...) need `forceReset`, which empties the
 * database first. `_prisma_migrations` is left alone.
 */
final class DbPush
{
    public function __construct(private readonly PrismaProject $project, private readonly Reporter $reporter) {}

    /** @return list<string> the statements that were executed */
    public function push(bool $acceptDataLoss = false, bool $forceReset = false): array
    {
        $document = $this->project->document();
        $url = $this->project->databaseUrl();
        $connection = $this->project->connect(true, fn (string $db) => $this->reporter->info(sprintf('Database %s created at %s', $db, $url->location())));
        if ($forceReset) {
            DatabaseReset::reset($connection);
            $this->reporter->info(sprintf('The %s at "%s" was successfully reset.', $url->provider === 'postgresql' ? '"' . $url->schema() . '" schema' : 'database', $url->location()));
        }
        $plan = (new Differ($connection))->plan(DatabaseSchema::introspect($connection), SchemaTranslator::translate($document));
        if ($plan->isEmpty()) {
            $this->reporter->info('The database is already in sync with the Prisma schema.');

            return [];
        }
        $warnings = $plan->warnings($connection, true);
        $unexecutable = array_values(array_filter($warnings, fn (MigrationWarning $w) => $w->unexecutable));
        if ($unexecutable !== []) {
            throw new PrismaException("We found changes that cannot be executed:\n\n" . implode("\n", array_map(fn ($w) => '  - ' . $w->message, $unexecutable))
                . "\n\nUse the --force-reset flag to drop the database before push like `prisma:db push --force-reset`\nAll data will be lost.");
        }
        $lossy = array_values(array_filter($warnings, fn (MigrationWarning $w) => ! $w->unexecutable));
        if ($lossy !== [] && ! $acceptDataLoss) {
            $list = implode("\n", array_map(fn ($w) => '  - ' . $w->message, $lossy));
            if (! $this->reporter->interactive()) {
                throw new PrismaException("There might be data loss when applying the changes:\n\n$list\n\nUse the --accept-data-loss flag to ignore the data loss warnings like `prisma:db push --accept-data-loss`");
            }
            $this->reporter->warn("There might be data loss when applying the changes:\n\n$list");
            if (! $this->reporter->confirm('Do you want to ignore the warning(s)?', false)) {
                throw new PrismaException('Push cancelled.');
            }
        }
        $schema = new Schema($connection);
        $statements = $plan->statements($schema);
        $plan->apply($schema);
        $this->reporter->info('Your database is now in sync with your Prisma schema.');

        return $statements;
    }
}
