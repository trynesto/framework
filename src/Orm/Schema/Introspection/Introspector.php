<?php

declare(strict_types=1);

namespace Nesto\Orm\Schema\Introspection;

use Nesto\Orm\Schema\Normalizer;
use Nesto\Orm\Schema\Table;
use Nesto\Orm\Connection;
use Nesto\Orm\Schema\Column;

/**
 * Reads the live schema of a database into Table objects (columns with their type as the server
 * reports it, nullability, default, auto-increment, comment, charset/collation; primary key; indexes and
 * unique constraints; foreign keys with their actions; checks).
 *
 *   $introspector = Introspector::for($connection);
 *   $introspector->tableNames();        // ['posts', 'users', ...]
 *   $introspector->table('posts');      // Table or null
 */
abstract class Introspector
{
    public function __construct(protected readonly Connection $connection) {}

    public static function for(Connection $connection): self
    {
        return match ($connection->driver()) {
            'sqlite' => new SqliteIntrospector($connection),
            'mysql' => new MysqlIntrospector($connection),
            default => new PostgresIntrospector($connection),
        };
    }

    /** @return list<string> base tables of the current database / schema */
    abstract public function tableNames(): array;

    abstract public function table(string $name): ?Table;

    /** @return array<string, bool> views of the current database / schema => materialized */
    abstract public function viewNames(): array;

    /**
     * The views of the database, with the expression recorded in the ORM's metadata table (TypeORM's
     * typeorm_metadata rows of type VIEW / MATERIALIZED_VIEW) — null for views it did not create.
     *
     * @param list<string>|null $only
     * @return array<string, \Nesto\Orm\Schema\View>
     */
    public function views(string $metadataTable, ?array $only = null): array
    {
        $names = $this->viewNames();
        $recorded = [];
        if (in_array($metadataTable, $this->tableNames(), true)) {
            $q = $this->connection->quote(...);
            $rows = $this->connection->fetchAll(
                'SELECT ' . $q('name') . ' AS name, ' . $q('value') . ' AS value, ' . $q('type') . ' AS type FROM ' . $q($metadataTable)
                . ' WHERE ' . $q('type') . " IN ('VIEW', 'MATERIALIZED_VIEW')",
            );
            foreach ($rows as $row) {
                $recorded[(string) $row['name']] = $row['value'] === null ? null : (string) $row['value'];
            }
        }
        $views = [];
        foreach ($names as $name => $materialized) {
            if ($only !== null && ! in_array($name, $only, true)) {
                continue;
            }
            $views[$name] = new \Nesto\Orm\Schema\View($name, $recorded[$name] ?? null, $materialized);
        }

        return $views;
    }

    /** @param list<string>|null $only @return array<string, Table> */
    public function tables(?array $only = null): array
    {
        $tables = [];
        $existing = $this->tableNames();
        foreach ($only ?? $existing as $name) {
            if (! in_array($name, $existing, true)) {
                continue;
            }
            $table = $this->table($name);
            if ($table !== null) {
                $tables[$name] = $table;
            }
        }

        return $tables;
    }

    /**
     * A Column for an introspected column: the abstract type Blueprint understands (so generated
     * migrations stay readable) plus the raw database type used for comparisons.
     */
    protected function column(string $name, string $rawType, bool $nullable, mixed $default, bool $autoIncrement = false): Column
    {
        [$canonical, $unsigned] = Normalizer::type($this->connection->platform(), $rawType);
        $array = str_ends_with($canonical, '[]'); // PostgreSQL array column
        [$type, $length, $precision, $scale] = $this->abstractType($array ? substr($canonical, 0, -2) : $canonical);
        $column = new Column($name, $type, $length, $precision, $scale);
        $column->array = $array;
        $column->rawType = $rawType;
        $column->nullable = $nullable;
        $column->unsigned = $unsigned;
        $column->autoIncrement = $autoIncrement;
        if ($default !== null && ! $autoIncrement) {
            $column->default = $default;
            $column->hasDefault = true;
        }

        return $column;
    }

    /** @return array{0: string, 1: ?int, 2: ?int, 3: ?int} [type, length, precision, scale] from a canonical type */
    protected function abstractType(string $canonical): array
    {
        if (preg_match('/^([a-z0-9_]+)(?:\((.*)\))?$/', $canonical, $m) !== 1) {
            return [$canonical, null, null, null];
        }
        $base = $m[1];
        $params = isset($m[2]) && $m[2] !== '' ? array_map('trim', explode(',', $m[2])) : [];
        $first = isset($params[0]) && is_numeric($params[0]) ? (int) $params[0] : null;
        $second = isset($params[1]) && is_numeric($params[1]) ? (int) $params[1] : null;
        $driver = $this->connection->driver();

        return match (true) {
            $canonical === 'tinyint(1)', $base === 'boolean', $base === 'bool' => ['bool', null, null, null],
            in_array($base, ['int', 'integer'], true) => ['int', null, null, null],
            in_array($base, ['bigint', 'smallint', 'tinyint', 'mediumint'], true) => [$base, null, null, null],
            in_array($base, ['decimal', 'numeric'], true) => ['decimal', null, $first ?? 10, $second ?? 0],
            $base === 'double' || ($base === 'real' && $driver === 'sqlite') => ['float', null, null, null],
            $base === 'real' => ['real', null, null, null],
            $base === 'float' => ['float', null, null, null],
            $base === 'varchar' => ['string', $first, null, null],
            $base === 'char' => ['char', $first, null, null],
            in_array($base, ['text', 'tinytext', 'mediumtext', 'longtext'], true) => [$base, null, null, null],
            $base === 'datetime' => ['datetime', null, $driver === 'sqlite' ? null : $first, null],
            $base === 'timestamp' => [$driver === 'mysql' ? 'timestamptz' : 'datetime', null, $first, null],
            $base === 'timestamptz' => ['timestamptz', null, $first, null],
            $base === 'time' => ['time', null, $first, null],
            $base === 'date' => ['date', null, null, null],
            in_array($base, ['json', 'jsonb'], true) => ['json', null, null, null],
            $base === 'uuid' => ['uuid', null, null, null],
            $base === 'enum' => ['enum', null, null, null],
            in_array($base, ['blob', 'bytea', 'longblob', 'mediumblob', 'tinyblob'], true) => [$base === 'bytea' ? 'blob' : $base, null, null, null],
            in_array($base, ['binary', 'varbinary'], true) => [$base, $first, null, null],
            default => [$base, $first, null, null],
        };
    }

    /** @return list<string> values of an "enum('a','b')" type */
    protected static function enumValues(string $rawType): array
    {
        if (preg_match('/^enum\s*\((.*)\)$/is', trim($rawType), $m) !== 1) {
            return [];
        }
        preg_match_all("/'((?:[^'\\\\]|''|\\\\.)*)'/s", $m[1], $values);

        return array_map(fn (string $v) => str_replace(["''", "\\'"], "'", $v), $values[1]);
    }
}
