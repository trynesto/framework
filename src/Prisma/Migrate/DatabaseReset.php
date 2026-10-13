<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

use Nesto\Orm\Connection;
use Nesto\Orm\Schema\Introspection\Introspector;

/**
 * Empties a database the way `prisma migrate reset` / `db push --force-reset` do: every table (including
 * `_prisma_migrations`), view and — on PostgreSQL — enum type, sequence and extension of the current schema is
 * dropped.
 */
final class DatabaseReset
{
    public static function reset(Connection $connection): void
    {
        $introspector = Introspector::for($connection);
        $q = fn (string $name) => $connection->quote($name);
        $driver = $connection->driver();
        if ($driver === 'mysql') {
            $connection->execute('SET FOREIGN_KEY_CHECKS = 0');
        }
        if ($driver === 'sqlite') {
            $connection->execute('PRAGMA foreign_keys = OFF');
        }
        try {
            foreach ($introspector->viewNames() as $view => $materialized) {
                $connection->execute('DROP ' . ($materialized ? 'MATERIALIZED ' : '') . 'VIEW IF EXISTS ' . $q($view) . ($driver === 'pgsql' ? ' CASCADE' : ''));
            }
            foreach ($introspector->tableNames() as $table) {
                $connection->execute('DROP TABLE IF EXISTS ' . $q($table) . ($driver === 'pgsql' ? ' CASCADE' : ''));
            }
            if ($driver === 'pgsql') {
                foreach (array_keys(DatabaseSchema::postgresEnums($connection)) as $type) {
                    $connection->execute('DROP TYPE IF EXISTS ' . $q($type) . ' CASCADE');
                }
                foreach ($connection->fetchAll("SELECT sequence_name AS name FROM information_schema.sequences WHERE sequence_schema = current_schema()") as $row) {
                    $connection->execute('DROP SEQUENCE IF EXISTS ' . $q((string) $row['name']) . ' CASCADE');
                }
                // Prisma drops the whole schema, with the extensions installed in it
                foreach ($connection->fetchAll("SELECT e.extname AS name FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace WHERE n.nspname = current_schema() AND e.extname <> 'plpgsql'") as $row) {
                    $connection->execute('DROP EXTENSION IF EXISTS ' . $q((string) $row['name']) . ' CASCADE');
                }
            }
        } finally {
            if ($driver === 'mysql') {
                $connection->execute('SET FOREIGN_KEY_CHECKS = 1');
            }
            if ($driver === 'sqlite') {
                $connection->execute('PRAGMA foreign_keys = ON');
            }
        }
    }
}
