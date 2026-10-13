<?php

declare(strict_types=1);

namespace Nesto\Orm\Migration;

use Closure;
use Nesto\Orm\Connection;
use Nesto\Orm\Schema\Introspection\Introspector;
use Nesto\Orm\Schema\Table;

/**
 * Schema manipulation API handed to migrations.
 *
 *   $schema->createTable('users', function (Blueprint $t) { $t->id(); $t->string('email')->unique(); $t->timestamps(); });
 *   $schema->addColumn('users', fn (Blueprint $t) => $t->string('nickname')->nullable());
 *   $schema->renameColumn('users', 'nickname', 'handle');
 *   $schema->dropColumn('users', 'handle');
 *   $schema->table('users', function (Blueprint $t) {            // alter: see Blueprint
 *       $t->string('email', 320)->change();
 *       $t->dropIndex(['nickname']);
 *   });
 *   $schema->dropTable('users');
 *   $schema->createView('active_users', 'SELECT id, name FROM users WHERE active = 1');   // recorded in typeorm_metadata
 *   $schema->createView('user_stats', 'SELECT ...', materialized: true);                // PostgreSQL
 *   $schema->dropView('active_users');
 *   $schema->raw('...');
 *   $sql = $schema->pretend(fn () => $schema->dropColumn('users', 'age'));   // statements, not executed
 */
final class Schema
{
    private readonly Grammar $grammar;
    /** @var list<string>|null statements recorded instead of executed (pretend()) */
    private ?array $pretended = null;

    /** TypeORM's default `metadataTableName`: where view definitions are recorded. */
    public const METADATA_TABLE = 'typeorm_metadata';

    public function __construct(private readonly Connection $connection, private readonly string $metadataTable = self::METADATA_TABLE)
    {
        $this->grammar = new Grammar($connection);
    }

    /** The table recording view definitions (the DataSource's `metadataTableName`). */
    public function metadataTable(): string
    {
        return $this->metadataTable;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function grammar(): Grammar
    {
        return $this->grammar;
    }

    /** @param Closure(Blueprint): void $define */
    public function createTable(string $table, Closure $define, bool $ifNotExists = false): void
    {
        $blueprint = new Blueprint($table);
        $define($blueprint);
        $this->createFromBlueprint($blueprint, $ifNotExists);
    }

    public function createFromBlueprint(Blueprint $blueprint, bool $ifNotExists = false): void
    {
        foreach ($this->grammar->compileCreate($blueprint, $ifNotExists) as $sql) {
            $this->statement($sql);
        }
    }

    public function dropTable(string $table, bool $ifExists = false): void
    {
        $this->statement($this->grammar->compileDropTable($table, $ifExists));
    }

    /**
     * Run $fn without executing DDL: returns the statements Schema would have run. Introspection still reads
     * the live database (alterations are computed against the current schema).
     *
     * @return list<string>
     */
    public function pretend(Closure $fn): array
    {
        $previous = $this->pretended;
        $this->pretended = [];
        try {
            $fn($this);

            return $this->pretended;
        } finally {
            $this->pretended = $previous;
        }
    }

    public function isPretending(): bool
    {
        return $this->pretended !== null;
    }

    /** Execute (or, when pretending, record) one DDL statement. */
    public function statement(string $sql, array $params = []): void
    {
        if ($this->pretended !== null) {
            $this->pretended[] = $sql;

            return;
        }
        $this->connection->execute($sql, $params);
    }

    /** The live schema of one table (null when it does not exist). */
    public function introspect(string $table): ?Table
    {
        return Introspector::for($this->connection)->table($table);
    }

    public function dropTableIfExists(string $table): void
    {
        $this->dropTable($table, true);
    }

    public function hasTable(string $table): bool
    {
        [$sql, $params] = $this->grammar->compileHasTable($table);

        return $this->connection->fetchOne($sql, $params) !== null;
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
    }

    /** Column names in table order. @return list<string> */
    public function columns(string $table): array
    {
        return array_column($this->columnDetails($table), 'name');
    }

    /**
     * Introspected columns in table order: name, database type (lower case, as the server reports it:
     * "bigint", "varchar", "jsonb", "longtext" for MariaDB JSON, ...) and nullability.
     *
     * @return list<array{name: string, type: string, nullable: bool}>
     */
    public function columnDetails(string $table): array
    {
        $rows = match ($this->connection->driver()) {
            'sqlite' => array_map(
                fn (array $r) => ['name' => $r['name'], 'type' => $r['type'], 'nullable' => (int) $r['notnull'] === 0 && (int) $r['pk'] === 0],
                array_values(array_filter( // table_xinfo: with generated columns (hidden 2 / 3)
                    $this->connection->fetchAll('PRAGMA table_xinfo(' . $this->connection->quote($table) . ')'),
                    fn (array $r) => (int) $r['hidden'] !== 1,
                )),
            ),
            // aliases keep the keys lower case (MySQL 8 returns information_schema columns in upper case)
            'mysql' => $this->connection->fetchAll(
                'SELECT column_name AS name, data_type AS type, is_nullable AS nullable FROM information_schema.columns'
                . ' WHERE table_schema = DATABASE() AND table_name = :t ORDER BY ordinal_position',
                ['t' => $table],
            ),
            default => $this->connection->fetchAll(
                'SELECT column_name AS name, udt_name AS type, is_nullable AS nullable FROM information_schema.columns'
                . ' WHERE table_schema = current_schema() AND table_name = :t ORDER BY ordinal_position',
                ['t' => $table],
            ),
        };

        return array_map(fn (array $r) => [
            'name' => (string) $r['name'],
            'type' => strtolower((string) $r['type']),
            'nullable' => is_bool($r['nullable']) ? $r['nullable'] : strtoupper((string) $r['nullable']) === 'YES',
        ], $rows);
    }

    /**
     * Alter an existing table: add columns / indexes / foreign keys / checks, `->change()` columns, drop or
     * rename things (see Blueprint). @param Closure(Blueprint): void $define
     */
    public function table(string $table, Closure $define): void
    {
        $blueprint = new Blueprint($table);
        $define($blueprint);
        $this->alterFromBlueprint($blueprint);
    }

    /**
     * Add the blueprint's columns, indexes and foreign keys to its existing table. SQLite cannot add a
     * constraint to a table: the foreign keys are added by rebuilding it (see SqliteTableRebuilder), which
     * fails without changing anything when existing rows violate them.
     */
    public function alterFromBlueprint(Blueprint $blueprint): void
    {
        if ($blueprint->altersExisting() || ($this->connection->driver() === 'sqlite' && $this->sqliteNeedsRebuild($blueprint))) {
            (new TableAlterer($this))->alter($blueprint);

            return;
        }
        foreach ($this->grammar->compileAddColumns($blueprint) as $sql) {
            $this->statement($sql);
        }
    }

    /** SQLite adds foreign keys and NOT NULL columns without default (no leftover DEFAULT) by rebuilding. */
    private function sqliteNeedsRebuild(Blueprint $blueprint): bool
    {
        foreach ($blueprint->foreignKeys as $fk) {
            if ($fk->referencedTable !== null) {
                return true;
            }
        }
        foreach ($blueprint->columns as $column) {
            if (! $column->nullable && ! $column->primary && ! ($column->hasDefault && $column->default !== null)) {
                return true;
            }
        }

        return false;
    }

    /** Alias of table(): `$schema->addColumn('users', fn (Blueprint $t) => $t->string('nickname')->nullable())`. */
    public function addColumn(string $table, Closure $define): void
    {
        $this->table($table, $define);
    }

    /**
     * Drop columns; indexes that use them are dropped with them. On SQLite a column in the primary key,
     * a UNIQUE constraint or a foreign key is removed by rebuilding the table (see SqliteTableRebuilder).
     */
    public function dropColumn(string $table, string ...$columns): void
    {
        if ($this->connection->driver() === 'sqlite' && ! $this->isPretending()) {
            (new SqliteTableRebuilder($this->connection))->dropColumns($table, array_values($columns));

            return;
        }
        if ($this->connection->driver() === 'sqlite') {
            $this->table($table, fn (Blueprint $t) => $t->dropColumn(...$columns));

            return;
        }
        foreach ($columns as $column) {
            $this->statement($this->grammar->compileDropColumn($table, $column));
        }
    }

    public function renameColumn(string $table, string $from, string $to): void
    {
        $this->statement($this->grammar->compileRenameColumn($table, $from, $to));
    }

    public function renameTable(string $from, string $to): void
    {
        $this->statement($this->grammar->compileRenameTable($from, $to));
    }

    /** Drop an index by name or columns: `$schema->dropIndex('posts', ['status'])`. @param string|list<string> $nameOrColumns */
    public function dropIndex(string $table, string|array $nameOrColumns): void
    {
        $this->table($table, fn (Blueprint $t) => $t->dropIndex($nameOrColumns));
    }

    /** @param string|list<string> $nameOrColumns */
    public function dropForeign(string $table, string|array $nameOrColumns): void
    {
        $this->table($table, fn (Blueprint $t) => $t->dropForeign($nameOrColumns));
    }

    // ---- views ------------------------------------------------------------------------------------

    /**
     * CREATE [MATERIALIZED] VIEW name AS expression, recording the expression in the metadata table (created
     * when missing) so schema sync can tell when the view changed. `materialized` is honoured on PostgreSQL;
     * other databases get a plain view.
     */
    public function createView(string $name, string $expression, bool $materialized = false): void
    {
        $materialized = $materialized && $this->connection->driver() === 'pgsql';
        $this->statement('CREATE ' . ($materialized ? 'MATERIALIZED ' : '') . 'VIEW ' . $this->connection->quote($name) . ' AS ' . trim($expression));
        $this->ensureMetadataTable();
        $q = $this->connection->quote(...);
        $this->statement(
            'INSERT INTO ' . $q($this->metadataTable) . ' (' . implode(', ', array_map($q, ['type', 'database', 'schema', 'table', 'name', 'value'])) . ') VALUES ('
            . $this->literal($materialized ? 'MATERIALIZED_VIEW' : 'VIEW') . ', NULL, NULL, NULL, ' . $this->literal($name) . ', ' . $this->literal(trim($expression)) . ')',
        );
    }

    /** DROP [MATERIALIZED] VIEW IF EXISTS name, and forget its recorded expression. */
    public function dropView(string $name, bool $materialized = false): void
    {
        $materialized = $materialized && $this->connection->driver() === 'pgsql';
        $this->statement('DROP ' . ($materialized ? 'MATERIALIZED ' : '') . 'VIEW IF EXISTS ' . $this->connection->quote($name));
        if ($this->hasTable($this->metadataTable)) {
            $q = $this->connection->quote(...);
            $this->statement('DELETE FROM ' . $q($this->metadataTable) . ' WHERE ' . $q('type') . " IN ('VIEW', 'MATERIALIZED_VIEW') AND " . $q('name') . ' = ' . $this->literal($name));
        }
    }

    public function hasView(string $name): bool
    {
        return array_key_exists($name, Introspector::for($this->connection)->viewNames());
    }

    /** The metadata table (TypeORM's typeorm_metadata: type, database, schema, table, name, value). */
    public function ensureMetadataTable(): void
    {
        $quoted = $this->connection->quote($this->metadataTable);
        $pretendedCreate = $this->pretended !== null && array_filter($this->pretended, fn (string $sql) => str_starts_with($sql, 'CREATE TABLE') && str_contains($sql, $quoted)) !== [];
        if ($pretendedCreate || $this->hasTable($this->metadataTable)) {
            return;
        }
        $blueprint = new Blueprint($this->metadataTable);
        $blueprint->string('type');
        $blueprint->string('database')->nullable();
        $blueprint->string('schema')->nullable();
        $blueprint->string('table')->nullable();
        $blueprint->string('name')->nullable();
        $blueprint->text('value')->nullable();
        $this->createFromBlueprint($blueprint, true);
    }

    /** A string literal quoted by the driver (statements stay complete in pretend() / schema:log output). */
    private function literal(string $value): string
    {
        return (string) $this->connection->pdo()->quote($value);
    }

    public function raw(string $sql, array $params = []): int
    {
        if ($this->pretended !== null) {
            $this->pretended[] = $sql;

            return 0;
        }

        return $this->connection->execute($sql, $params);
    }
}
