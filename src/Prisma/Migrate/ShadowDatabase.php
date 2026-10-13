<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

use Nesto\Orm\Connection;

/**
 * A throwaway database for migrate dev / diff: the migration history is replayed into it to know the schema
 * the migrations produce. Without `shadowDatabaseUrl` it is created next to the main database
 * ("prisma_migrate_shadow_db_<uuid>" on the same PostgreSQL / MySQL server, a temporary file for SQLite) and
 * dropped by drop(); with `shadowDatabaseUrl` that database is emptied and reused.
 *
 *   $shadow = ShadowDatabase::create($url, $shadowUrl);
 *   try { $shadow->connection(); ... } finally { $shadow->drop(); }
 */
final class ShadowDatabase
{
    private ?Connection $connection = null;

    private function __construct(private readonly DatabaseUrl $url, private readonly bool $temporary) {}

    public static function create(DatabaseUrl $main, ?DatabaseUrl $configured = null): self
    {
        if ($configured !== null) {
            $shadow = new self($configured, false);
            DatabaseReset::reset($shadow->connection());

            return $shadow;
        }
        $id = bin2hex(random_bytes(8));
        if ($main->provider === 'sqlite') {
            $file = sys_get_temp_dir() . '/prisma_migrate_shadow_db_' . $id . '.db';

            return new self(new DatabaseUrl('file:' . $file, 'sqlite'), true);
        }
        $url = $main->withDatabase('prisma_migrate_shadow_db_' . $id);
        try {
            $url->createDatabase();
        } catch (\PDOException $e) {
            throw new \Nesto\Prisma\PrismaException(
                'Prisma Migrate could not create the shadow database. Please make sure the database user has permission to create databases, '
                . 'or set `shadowDatabaseUrl` in the datasource. Original error: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        return new self($url, true);
    }

    public function connection(): Connection
    {
        if ($this->connection === null) {
            $this->connection = $this->url->connect();
            if ($this->url->provider === 'postgresql' && $this->url->schema() !== 'public') {
                $this->connection->execute('CREATE SCHEMA IF NOT EXISTS ' . $this->connection->quote($this->url->schema()));
            }
        }

        return $this->connection;
    }

    public function url(): DatabaseUrl
    {
        return $this->url;
    }

    public function drop(): void
    {
        $this->connection = null; // close it (PostgreSQL refuses to drop a database in use)
        gc_collect_cycles();
        if ($this->temporary) {
            try {
                $this->url->dropDatabase();
            } catch (\Throwable) {
                // best effort: a leftover shadow database is harmless
            }
        }
    }
}
