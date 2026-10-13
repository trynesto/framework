<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

use Nesto\Orm\Connection;
use Nesto\Orm\Schema\Introspection\Introspector;
use Nesto\Orm\Schema\Table;

/**
 * A database schema in the ORM's neutral model: tables (Orm\Schema\Table) plus, on PostgreSQL, the native
 * enum types (name => values) and the extensions (name => schema / version). Built from a schema
 * (SchemaTranslator) or read from a live database (introspect()), and compared by Differ.
 */
final class DatabaseSchema
{
    /** Prisma's migrations table: never part of a diff. */
    public const MIGRATIONS_TABLE = '_prisma_migrations';

    /**
     * @param array<string, Table>        $tables     in creation order
     * @param array<string, list<string>> $enums      PostgreSQL enum types
     * @param array<string, array{schema: ?string, version: ?string}> $extensions PostgreSQL extensions (database name =>
     *        schema / version; null = whatever the database chooses)
     */
    public function __construct(public readonly array $tables = [], public readonly array $enums = [], public readonly array $extensions = []) {}

    public static function empty(): self
    {
        return new self();
    }

    /** What the database has (without `_prisma_migrations`). */
    public static function introspect(Connection $connection): self
    {
        $introspector = Introspector::for($connection);
        $tables = [];
        foreach ($introspector->tables() as $name => $table) {
            if ($name !== self::MIGRATIONS_TABLE) {
                $tables[$name] = $table;
            }
        }

        if ($connection->platform() === 'mariadb') {
            $tables = self::markMariaDbJson($connection, $tables);
        }

        $pgsql = $connection->driver() === 'pgsql';

        return new self($tables, $pgsql ? self::postgresEnums($connection) : [], $pgsql ? self::postgresExtensions($connection) : []);
    }

    /**
     * MariaDB's JSON is LONGTEXT + CHECK (json_valid(col)): the introspector reports the LONGTEXT, give those
     * columns the abstract type "json" back (db pull maps them to Json).
     *
     * @param array<string, Table> $tables
     * @return array<string, Table>
     */
    private static function markMariaDbJson(Connection $connection, array $tables): array
    {
        $rows = $connection->fetchAll(
            "SELECT table_name AS t, constraint_name AS c, check_clause AS clause FROM information_schema.check_constraints WHERE constraint_schema = DATABASE()",
        );
        foreach ($rows as $row) {
            $table = $tables[(string) $row['t']] ?? null;
            if ($table === null || preg_match('/^json_valid\(`?([^`)]+)`?\)$/i', trim((string) $row['clause']), $m) !== 1 || $m[1] !== $row['c']) {
                continue;
            }
            $column = $table->column($m[1]);
            if ($column === null || $column->type === 'json') {
                continue;
            }
            $json = new \Nesto\Orm\Schema\Column($column->name, 'json');
            foreach (get_object_vars($column) as $property => $value) {
                if (! in_array($property, ['name', 'type', 'length', 'precision', 'scale'], true)) {
                    $json->{$property} = $value;
                }
            }
            $table->columns[$column->name] = $json;
        }

        return $tables;
    }

    /** @return array<string, list<string>> enum type => labels, in the current schema */
    public static function postgresEnums(Connection $connection): array
    {
        $enums = [];
        foreach ($connection->fetchAll(
            'SELECT t.typname AS name, e.enumlabel AS label FROM pg_type t JOIN pg_enum e ON e.enumtypid = t.oid'
            . ' JOIN pg_namespace n ON n.oid = t.typnamespace WHERE n.nspname = current_schema() ORDER BY t.typname, e.enumsortorder',
        ) as $row) {
            $enums[(string) $row['name']][] = (string) $row['label'];
        }

        return $enums;
    }

    /**
     * Installed extensions (plpgsql, which every database has, left out).
     *
     * @return array<string, array{schema: string, version: string}>
     */
    public static function postgresExtensions(Connection $connection): array
    {
        $extensions = [];
        foreach ($connection->fetchAll(
            "SELECT e.extname AS name, e.extversion AS version, n.nspname AS schema FROM pg_extension e"
            . " JOIN pg_namespace n ON n.oid = e.extnamespace WHERE e.extname <> 'plpgsql' ORDER BY e.extname",
        ) as $row) {
            $extensions[(string) $row['name']] = ['schema' => (string) $row['schema'], 'version' => (string) $row['version']];
        }

        return $extensions;
    }

    /** No tables and no enum types (extensions do not count: a fresh database may come with some). */
    public function isEmpty(): bool
    {
        return $this->tables === [] && $this->enums === [];
    }
}
