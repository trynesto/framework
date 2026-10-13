<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

use Nesto\Orm\Connection;
use Nesto\Prisma\PrismaException;

/**
 * Runs migration scripts. PostgreSQL scripts run in one transaction; MySQL / MariaDB DDL cannot be rolled back
 * and SQLite scripts toggle PRAGMA foreign_keys (a no-op inside a transaction), so those run statement by
 * statement. With recording, `_prisma_migrations` gets a row that is finished on success or keeps the error
 * in `logs` on failure (the migration then blocks deploy until `migrate resolve`).
 */
final class MigrationRunner
{
    public function __construct(private readonly Connection $connection) {}

    public function apply(LocalMigration $migration, bool $record = true): void
    {
        $table = new MigrationsTable($this->connection);
        $id = $record ? $table->start($migration->name, $migration->checksum()) : null;
        try {
            $this->run($migration->sql());
        } catch (\Throwable $e) {
            if ($id !== null) {
                try {
                    $table->fail($id, $e->getMessage());
                } catch (\Throwable) {
                }
            }
            throw new PrismaException(sprintf(
                "A migration failed to apply. New migrations cannot be applied before the error is recovered from. Read more about how to resolve migration issues in a production database: https://pris.ly/d/migrate-resolve\n\nMigration name: %s\n\nDatabase error:\n%s",
                $migration->name,
                $e->getMessage(),
            ), 3018, $e);
        }
        if ($id !== null) {
            $table->finish($id);
        }
    }

    /** Execute a SQL script. */
    public function run(string $sql): void
    {
        $statements = SqlScript::split($sql, $this->connection->driver() === 'mysql');
        if ($statements === []) {
            return;
        }
        if ($this->connection->driver() === 'pgsql') {
            $this->connection->transaction(function () use ($statements): void {
                foreach ($statements as $statement) {
                    $this->connection->execute($statement);
                }
            });

            return;
        }
        foreach ($statements as $statement) {
            $this->connection->execute($statement);
        }
    }
}
