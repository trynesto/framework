<?php

declare(strict_types=1);

namespace Nesto\Prisma\Testing;

use Nesto\Orm\Connection;
use Nesto\Prisma\Client\PrismaClient;
use Nesto\Prisma\Dmmf\Document;
use Nesto\Prisma\Dmmf\EnvValue;
use Nesto\Prisma\Migrate\BufferedReporter;
use Nesto\Prisma\Migrate\DatabaseUrl;
use Nesto\Prisma\Migrate\DbPush;
use Nesto\Prisma\PrismaException;
use Nesto\Prisma\PrismaProject;

/**
 * Test databases for Prisma code:
 *
 *   PrismaTestDatabase::refresh($prisma, 'prisma/schema.prisma', getenv('TEST_DATABASE_URL'));   // before each test
 *
 * refresh() points the client at $url (like Prisma's `datasourceUrl`; an app's PrismaService already connected
 * to the development database is moved to the test database), pushes the schema there once per process and
 * empties every table. Only the database of $url is touched.
 *
 *   PrismaTestDatabase::push('prisma/schema.prisma', $url);   // db push --force-reset --accept-data-loss, once
 *   PrismaTestDatabase::truncate($prisma);                    // empty every table of the client's database
 *
 * push() points the datasource's environment variables (`url`, and `directUrl` when declared) at $url for this
 * process and runs `db push --force-reset --accept-data-loss` — the database is created when missing and
 * emptied first. truncate() empties every model table and implicit many-to-many table and restarts
 * auto-increment counters (PostgreSQL TRUNCATE ... RESTART IDENTITY CASCADE, MySQL / MariaDB TRUNCATE without
 * foreign key checks, SQLite DELETE + sqlite_sequence).
 *
 * Safety (like the ORM's driver tests): a database whose name does not contain "test" (SQLite: the file name)
 * is never pushed to or emptied unless `force: true` is passed. In-memory SQLite databases are always allowed.
 *
 * See RefreshPrismaDatabase for the PHPUnit trait.
 */
final class PrismaTestDatabase
{
    /** @var array<string, true> schema + url already pushed in this process */
    private static array $pushed = [];

    /**
     * Point $prisma at $url (when given), push the schema there once per process (when $schemaPath is given) and
     * empty every table of the client's database.
     */
    public static function refresh(PrismaClient $prisma, ?string $schemaPath = null, ?string $url = null, bool $force = false): void
    {
        if ($url !== null && $url !== '') {
            $provider = $prisma->dmmf()->requireDatasource()->provider;
            self::assertTestDatabase((new DatabaseUrl($url, $provider))->database(), $force);
            $prisma->useDatasourceUrl($url);
        }
        if ($schemaPath !== null) {
            self::push($schemaPath, $url, force: $force);
        }
        self::truncate($prisma, $force);
    }

    /**
     * db push --force-reset the schema to $url (or the configured URL). Returns false when it was already pushed
     * in this process (pass $again to push anyway).
     */
    public static function push(string $schemaPath, ?string $url = null, bool $again = false, bool $force = false): bool
    {
        $project = new PrismaProject($schemaPath, dirname($schemaPath, 2));
        if ($url !== null && $url !== '') {
            self::pointAt($project, $url);
        }
        $target = $project->databaseUrl();
        self::assertTestDatabase($target->database(), $force);
        $key = realpath($schemaPath) . '|' . $target->url . '|' . md5((string) @file_get_contents($schemaPath));
        if (! $again && isset(self::$pushed[$key])) {
            return false;
        }
        (new DbPush($project, new BufferedReporter()))->push(acceptDataLoss: true, forceReset: true);
        self::$pushed[$key] = true;

        return true;
    }

    /** Point the datasource's `env("...")` variables (`url`, and `directUrl` when declared) at $url for this process. */
    public static function pointAt(PrismaProject $project, string $url): void
    {
        $datasource = $project->document()->requireDatasource();
        self::pointVariable($datasource->url, 'url', $url);
        if ($datasource->directUrl !== null) {
            self::pointVariable($datasource->directUrl, 'directUrl', $url);
        }
    }

    private static function pointVariable(EnvValue $value, string $property, string $url): void
    {
        $variable = $value->fromEnvVar;
        if ($variable === null) {
            if ($value->value !== $url) {
                throw new PrismaException(sprintf('The datasource `%1$s` of the schema is a literal; use %1$s = env("...") to point tests at another database.', $property));
            }

            return;
        }
        $_ENV[$variable] = $_SERVER[$variable] = $url;
        putenv($variable . '=' . $url);
    }

    /** The name of the database $connection works on (SQLite: the file path, ":memory:" in memory). */
    public static function databaseName(Connection $connection): string
    {
        $driver = $connection->driver();
        if ($driver === 'pgsql') {
            return (string) $connection->fetchColumn('SELECT current_database()');
        }
        if ($driver === 'mysql') {
            return (string) $connection->fetchColumn('SELECT DATABASE()');
        }
        foreach ($connection->fetchAll('PRAGMA database_list') as $row) {
            if (($row['name'] ?? null) === 'main') {
                return (string) ($row['file'] ?? '') !== '' ? (string) $row['file'] : ':memory:';
            }
        }

        return ':memory:';
    }

    /** True for a throwaway database: its name (SQLite: the file name) contains "test", or SQLite in memory. */
    public static function isTestDatabase(string $name): bool
    {
        if ($name === '' || $name === ':memory:') {
            return true;
        }

        return str_contains(strtolower(basename($name)), 'test');
    }

    private static function assertTestDatabase(string $name, bool $force): void
    {
        if ($force || self::isTestDatabase($name)) {
            return;
        }
        throw new PrismaException(sprintf(
            'Refusing to reset the database "%s": its name does not contain "test". The Prisma test helpers only push to and empty throwaway databases; point them at one (e.g. TEST_DATABASE_URL) or pass force: true.',
            $name,
        ));
    }

    /** Empty every table of the client's schema in the database the client is connected to. */
    public static function truncate(PrismaClient $prisma, bool $force = false): void
    {
        self::truncateTables($prisma->connection(), $prisma->dmmf(), $force);
    }

    public static function truncateTables(Connection $connection, Document $document, bool $force = false): void
    {
        self::assertTestDatabase(self::databaseName($connection), $force);
        $tables = [];
        foreach ($document->models() as $model) {
            $tables[] = $model->tableName();
        }
        foreach ($document->manyToMany as $relation) {
            $tables[] = $relation->table;
        }
        if ($tables === []) {
            return;
        }
        $q = fn (string $name) => $connection->quote($name);
        switch ($connection->driver()) {
            case 'pgsql':
                $connection->execute('TRUNCATE TABLE ' . implode(', ', array_map($q, $tables)) . ' RESTART IDENTITY CASCADE');
                break;
            case 'mysql':
                $connection->execute('SET FOREIGN_KEY_CHECKS = 0');
                try {
                    foreach ($tables as $table) {
                        $connection->execute('TRUNCATE TABLE ' . $q($table));
                    }
                } finally {
                    $connection->execute('SET FOREIGN_KEY_CHECKS = 1');
                }
                break;
            default:
                $connection->execute('PRAGMA foreign_keys = OFF');
                try {
                    foreach ($tables as $table) {
                        $connection->execute('DELETE FROM ' . $q($table));
                    }
                    if ($connection->fetchOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'") !== null) {
                        $connection->execute('DELETE FROM sqlite_sequence');
                    }
                } finally {
                    $connection->execute('PRAGMA foreign_keys = ON');
                }
        }
    }
}
