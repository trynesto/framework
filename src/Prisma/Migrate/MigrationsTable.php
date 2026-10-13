<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

use Nesto\Orm\Connection;

/**
 * The `_prisma_migrations` table, with Prisma's exact layout per database (id, checksum, finished_at,
 * migration_name, logs, rolled_back_at, started_at, applied_steps_count). A row per applied (or failed /
 * rolled back) migration; `checksum` is the SHA-256 of the migration.sql bytes.
 */
final class MigrationsTable
{
    public const NAME = '_prisma_migrations';

    public function __construct(private readonly Connection $connection) {}

    public function exists(): bool
    {
        return in_array(self::NAME, \Nesto\Orm\Schema\Introspection\Introspector::for($this->connection)->tableNames(), true);
    }

    public function ensure(): void
    {
        if ($this->exists()) {
            return;
        }
        $this->connection->execute(match ($this->connection->driver()) {
            'pgsql' => <<<'SQL'
                CREATE TABLE "_prisma_migrations" (
                    "id"                    VARCHAR(36) PRIMARY KEY NOT NULL,
                    "checksum"              VARCHAR(64) NOT NULL,
                    "finished_at"           TIMESTAMPTZ,
                    "migration_name"        VARCHAR(255) NOT NULL,
                    "logs"                  TEXT,
                    "rolled_back_at"        TIMESTAMPTZ,
                    "started_at"            TIMESTAMPTZ NOT NULL DEFAULT now(),
                    "applied_steps_count"   INTEGER NOT NULL DEFAULT 0
                )
                SQL,
            'mysql' => <<<'SQL'
                CREATE TABLE _prisma_migrations (
                    id                      VARCHAR(36) PRIMARY KEY NOT NULL,
                    checksum                VARCHAR(64) NOT NULL,
                    finished_at             DATETIME(3),
                    migration_name          VARCHAR(255) NOT NULL,
                    logs                    TEXT,
                    rolled_back_at          DATETIME(3),
                    started_at              DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                    applied_steps_count     INTEGER UNSIGNED NOT NULL DEFAULT 0
                ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
                SQL,
            default => <<<'SQL'
                CREATE TABLE "_prisma_migrations" (
                    "id"                    TEXT PRIMARY KEY NOT NULL,
                    "checksum"              TEXT NOT NULL,
                    "finished_at"           DATETIME,
                    "migration_name"        TEXT NOT NULL,
                    "logs"                  TEXT,
                    "rolled_back_at"        DATETIME,
                    "started_at"            DATETIME NOT NULL DEFAULT current_timestamp,
                    "applied_steps_count"   INTEGER UNSIGNED NOT NULL DEFAULT 0
                )
                SQL,
        });
    }

    /** Every row, oldest first. @return list<MigrationRecord> */
    public function records(): array
    {
        if (! $this->exists()) {
            return [];
        }
        $rows = $this->connection->fetchAll('SELECT * FROM ' . $this->connection->quote(self::NAME) . ' ORDER BY started_at ASC, migration_name ASC');

        return array_map(fn (array $row) => new MigrationRecord(
            (string) $row['id'],
            (string) $row['checksum'],
            (string) $row['migration_name'],
            $row['started_at'] !== null ? (string) $row['started_at'] : null,
            $row['finished_at'] !== null ? (string) $row['finished_at'] : null,
            $row['rolled_back_at'] !== null ? (string) $row['rolled_back_at'] : null,
            $row['logs'] !== null ? (string) $row['logs'] : null,
            (int) $row['applied_steps_count'],
        ), $rows);
    }

    /** Record the start of a migration; returns the row id. */
    public function start(string $name, string $checksum): string
    {
        $this->ensure();
        $id = self::uuid();
        $this->connection->execute(
            'INSERT INTO ' . $this->table() . ' (' . $this->columns(['id', 'checksum', 'migration_name', 'started_at', 'applied_steps_count']) . ') VALUES (:id, :checksum, :name, ' . $this->now() . ', 0)',
            ['id' => $id, 'checksum' => $checksum, 'name' => $name],
        );

        return $id;
    }

    public function finish(string $id): void
    {
        $this->connection->execute(
            'UPDATE ' . $this->table() . ' SET ' . $this->q('finished_at') . ' = ' . $this->now() . ', ' . $this->q('applied_steps_count') . ' = 1 WHERE ' . $this->q('id') . ' = :id',
            ['id' => $id],
        );
    }

    public function fail(string $id, string $logs): void
    {
        $this->connection->execute('UPDATE ' . $this->table() . ' SET ' . $this->q('logs') . ' = :logs WHERE ' . $this->q('id') . ' = :id', ['id' => $id, 'logs' => $logs]);
    }

    /** `migrate resolve --applied`: record the migration as applied without running it. */
    public function markApplied(string $name, string $checksum): void
    {
        $this->ensure();
        $this->connection->execute(
            'INSERT INTO ' . $this->table() . ' (' . $this->columns(['id', 'checksum', 'migration_name', 'started_at', 'finished_at', 'applied_steps_count']) . ') VALUES (:id, :checksum, :name, ' . $this->now() . ', ' . $this->now() . ', 0)',
            ['id' => self::uuid(), 'checksum' => $checksum, 'name' => $name],
        );
    }

    /** `migrate resolve --rolled-back`: mark the failed rows of the migration as rolled back. */
    public function markRolledBack(string $name): int
    {
        return $this->connection->execute(
            'UPDATE ' . $this->table() . ' SET ' . $this->q('rolled_back_at') . ' = ' . $this->now() . ' WHERE ' . $this->q('migration_name') . ' = :name AND '
            . $this->q('finished_at') . ' IS NULL AND ' . $this->q('rolled_back_at') . ' IS NULL',
            ['name' => $name],
        );
    }

    private function now(): string
    {
        return match ($this->connection->driver()) {
            'pgsql' => 'now()',
            'mysql' => 'CURRENT_TIMESTAMP(3)',
            default => "strftime('%Y-%m-%d %H:%M:%f', 'now')",
        };
    }

    private function table(): string
    {
        return $this->connection->quote(self::NAME);
    }

    private function q(string $column): string
    {
        return $this->connection->quote($column);
    }

    /** @param list<string> $columns */
    private function columns(array $columns): string
    {
        return implode(', ', array_map(fn (string $c) => $this->q($c), $columns));
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
