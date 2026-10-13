<?php

declare(strict_types=1);

namespace Nesto\Orm\Migration;

use Nesto\Orm\Connection;
use Nesto\Orm\Schema\Index;
use Nesto\Orm\Schema\Table;
use Nesto\Orm\OrmException;

/**
 * SQLite ALTERs that ALTER TABLE cannot do, through the table rebuild SQLite documents
 * (https://www.sqlite.org/lang_altertable.html#otheralter): create the new table, copy the rows, drop the
 * old one, rename, recreate its indexes and triggers — in one transaction, with foreign keys switched off
 * while the old table is dropped and PRAGMA foreign_key_check run before committing.
 *
 *   $rebuilder->dropColumns('books', ['isbn']);                  // indexes on the column are dropped too
 *   $rebuilder->addForeignKeys('books', [$foreignKeyDefinition]); // fails if existing rows violate it
 *
 * Inside an open transaction with foreign keys enforced, enforcement cannot be switched off: the rebuild is
 * then refused when a foreign key references the table (dropping the old table would cascade to / fail on
 * the referencing rows). Migrations are not affected: the Migrator switches enforcement off around them.
 */
final class SqliteTableRebuilder
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * Drop columns. Indexes that use a dropped column are dropped (as PostgreSQL does); a column that is
     * part of the primary key, a UNIQUE constraint or a foreign key needs a rebuild, others use ALTER TABLE.
     *
     * @param list<string> $columns
     */
    public function dropColumns(string $table, array $columns): void
    {
        $columns = array_values(array_unique($columns));
        $info = array_filter($this->connection->fetchAll('PRAGMA table_xinfo(' . $this->q($table) . ')'), fn (array $r) => (int) $r['hidden'] !== 1);
        $known = array_column($info, 'name');
        $stored = array_column(array_filter($info, fn (array $r) => (int) $r['hidden'] === 0), 'name'); // generated columns are recomputed
        foreach ($columns as $column) {
            if (! in_array($column, $known, true)) {
                throw new OrmException("Cannot drop column \"$column\": table \"$table\" has no such column.");
            }
        }
        if (array_intersect($columns, $this->constrainedColumns($table)) === []) {
            $this->connection->transaction(function () use ($table, $columns): void {
                foreach ($this->indexes($table) as $index) {
                    if ($index['sql'] !== null && array_intersect($index['columns'], $columns) !== []) {
                        $this->connection->execute('DROP INDEX ' . $this->q($index['name']));
                    }
                }
                foreach ($columns as $column) {
                    $this->connection->execute('ALTER TABLE ' . $this->q($table) . ' DROP COLUMN ' . $this->q($column));
                }
            });

            return;
        }
        $this->rebuild($table, $this->definitionWithout($table, $columns), array_values(array_diff($stored, $columns)), $columns);
    }

    /** Add FOREIGN KEY constraints to an existing table (its columns must exist already). @param list<ForeignKeyDefinition> $foreignKeys */
    public function addForeignKeys(string $table, array $foreignKeys, Grammar $grammar): void
    {
        if ($foreignKeys === []) {
            return;
        }
        $sql = $this->createSql($table);
        $close = strrpos($sql, ')');
        if ($close === false) {
            throw new OrmException("Cannot parse the definition of table \"$table\".");
        }
        $clauses = array_map(fn (ForeignKeyDefinition $fk) => $grammar->foreignKeySql($fk), $foreignKeys);
        $sql = rtrim(substr($sql, 0, $close)) . ', ' . implode(', ', $clauses) . substr($sql, $close);
        $columns = array_column($this->connection->fetchAll('PRAGMA table_info(' . $this->q($table) . ')'), 'name');
        $this->rebuild($table, $sql, $columns, []);
    }

    /**
     * Rebuild a table to a new definition (TableAlterer: changed columns, keys, constraints...). `$copy` maps
     * each new column to the SQL expression that fills it from the old table (columns absent from it get
     * their default). Indexes are recreated from the new definition; expression indexes and triggers from
     * their original SQL. When the Schema is pretending, the statements are only recorded.
     *
     * @param array<string, string> $copy
     */
    public function rebuildTo(Table $new, array $copy, Grammar $grammar, Schema $schema): void
    {
        $table = $new->name;
        $temp = '__nesto_new_' . $table;
        $definition = $new->copy();
        $expressionIndexes = array_filter($definition->indexes, fn (Index $i) => $i->implicit);
        $definition->indexes = array_values(array_filter($definition->indexes, fn (Index $i) => ! $i->implicit));
        $statements = $grammar->compileCreate(Blueprint::fromTable($definition));
        $create = $this->renamed((string) array_shift($statements), $temp);
        $objects = $this->connection->fetchAll(
            "SELECT type, name, sql FROM sqlite_master WHERE tbl_name = :t AND type IN ('index', 'trigger') AND sql IS NOT NULL",
            ['t' => $table],
        );
        $keep = array_map(fn (Index $i) => strtolower((string) $i->name), $expressionIndexes);
        $after = $statements;
        foreach ($objects as $object) {
            if ($object['type'] === 'trigger' || in_array(strtolower((string) $object['name']), $keep, true)) {
                $after[] = (string) $object['sql'];
            }
        }
        $columns = implode(', ', array_map(fn (string $c) => $this->q($c), array_keys($copy)));
        $values = implode(', ', $copy);
        $steps = [
            $create,
            'INSERT INTO ' . $this->q($temp) . " ($columns) SELECT $values FROM " . $this->q($table),
            'DROP TABLE ' . $this->q($table),
            'ALTER TABLE ' . $this->q($temp) . ' RENAME TO ' . $this->q($table),
            ...$after,
        ];
        if ($copy === []) {
            unset($steps[1]);
        }
        if ($schema->isPretending()) {
            foreach ($steps as $sql) {
                $schema->statement($sql);
            }

            return;
        }
        $this->runRebuild($table, array_values($steps), preg_match('/\bAUTOINCREMENT\b/i', $create) === 1);
    }

    /** Run rebuild statements in a transaction with foreign keys off (see rebuild()). @param list<string> $steps */
    private function runRebuild(string $table, array $steps, bool $autoIncrement): void
    {
        $conn = $this->connection;
        $enforced = (int) $conn->fetchColumn('PRAGMA foreign_keys') === 1;
        $nested = $conn->inTransaction();
        if ($enforced && $nested && $this->referencingTables($table) !== []) {
            throw new OrmException(sprintf(
                'Cannot rebuild SQLite table "%s" inside a transaction while foreign keys are enforced: other tables reference it. Run it outside the transaction (migrations do this automatically).',
                $table,
            ));
        }
        if ($enforced && ! $nested) {
            $conn->execute('PRAGMA foreign_keys = OFF');
        }
        try {
            $conn->transaction(function () use ($conn, $table, $steps, $autoIncrement, $enforced): void {
                $sequence = $this->hasSequenceTable() ? $conn->fetchColumn('SELECT seq FROM sqlite_sequence WHERE name = :t', ['t' => $table]) : null;
                foreach ($steps as $sql) {
                    $conn->execute($sql);
                }
                if ($sequence !== null && $autoIncrement) {
                    $copied = (int) $conn->fetchColumn('SELECT seq FROM sqlite_sequence WHERE name = :t', ['t' => $table]);
                    $conn->execute('DELETE FROM sqlite_sequence WHERE name = :t', ['t' => $table]);
                    $conn->execute('INSERT INTO sqlite_sequence (name, seq) VALUES (:t, :s)', ['t' => $table, 's' => max($copied, (int) $sequence)]);
                }
                if ($enforced) {
                    $violations = $conn->fetchAll('PRAGMA foreign_key_check');
                    if ($violations !== []) {
                        $first = $violations[0];
                        throw new OrmException(sprintf(
                            'Altering table "%s" would break %d foreign key reference%s (first: "%s" row %s → "%s"); nothing was changed.',
                            $table, count($violations), count($violations) === 1 ? '' : 's', $first['table'], $first['rowid'] ?? '?', $first['parent'],
                        ));
                    }
                }
            });
        } finally {
            if ($enforced && ! $nested) {
                $conn->execute('PRAGMA foreign_keys = ON');
            }
        }
    }

    /**
     * @param string       $createSql CREATE TABLE statement of the new definition (any table name: it is replaced)
     * @param list<string> $copy      columns copied from the old table
     * @param list<string> $dropped   columns removed: indexes using them are not recreated
     */
    private function rebuild(string $table, string $createSql, array $copy, array $dropped): void
    {
        $conn = $this->connection;
        $enforced = (int) $conn->fetchColumn('PRAGMA foreign_keys') === 1;
        $nested = $conn->inTransaction();
        if ($enforced && $nested) {
            // PRAGMA foreign_keys is a no-op inside a transaction: dropping the old table would run the
            // ON DELETE actions of every foreign key pointing at it (or fail on them)
            $referencing = $this->referencingTables($table);
            if ($referencing !== []) {
                throw new OrmException(sprintf(
                    'Cannot rebuild SQLite table "%s" inside a transaction while foreign keys are enforced: %s reference%s it, and dropping the old table would cascade to / fail on %s. Run it outside the transaction (migrations do this automatically).',
                    $table, implode(', ', array_map(fn ($t) => "\"$t\"", $referencing)), count($referencing) === 1 ? 's' : '', count($referencing) === 1 ? 'it' : 'them',
                ));
            }
        }
        if ($enforced && ! $nested) {
            $conn->execute('PRAGMA foreign_keys = OFF');
        }
        try {
            $conn->transaction(function () use ($conn, $table, $createSql, $copy, $dropped, $enforced): void {
                $temp = '__nesto_new_' . $table;
                $objects = $conn->fetchAll(
                    "SELECT type, name, sql FROM sqlite_master WHERE tbl_name = :t AND type IN ('index', 'trigger') AND sql IS NOT NULL",
                    ['t' => $table],
                );
                $indexColumns = array_column($this->indexes($table), 'columns', 'name');
                $sequence = $this->hasSequenceTable() ? $conn->fetchColumn('SELECT seq FROM sqlite_sequence WHERE name = :t', ['t' => $table]) : null;
                $columnList = implode(', ', array_map(fn (string $c) => $this->q($c), $copy));

                $conn->execute($this->renamed($createSql, $temp));
                $conn->execute('INSERT INTO ' . $this->q($temp) . " ($columnList) SELECT $columnList FROM " . $this->q($table));
                $conn->execute('DROP TABLE ' . $this->q($table));
                $conn->execute('ALTER TABLE ' . $this->q($temp) . ' RENAME TO ' . $this->q($table));
                foreach ($objects as $object) {
                    if ($object['type'] === 'index' && array_intersect($indexColumns[$object['name']] ?? [], $dropped) !== []) {
                        continue;
                    }
                    $conn->execute((string) $object['sql']);
                }
                if ($sequence !== null && preg_match('/\bAUTOINCREMENT\b/i', $createSql) === 1) {
                    // keep AUTOINCREMENT from reusing the ids of deleted rows
                    $copied = (int) $conn->fetchColumn('SELECT seq FROM sqlite_sequence WHERE name = :t', ['t' => $table]);
                    $conn->execute('DELETE FROM sqlite_sequence WHERE name = :t', ['t' => $table]);
                    $conn->execute('INSERT INTO sqlite_sequence (name, seq) VALUES (:t, :s)', ['t' => $table, 's' => max($copied, (int) $sequence)]);
                }
                if ($enforced) {
                    $violations = $conn->fetchAll('PRAGMA foreign_key_check');
                    if ($violations !== []) {
                        $first = $violations[0];
                        throw new OrmException(sprintf(
                            'Altering table "%s" would break %d foreign key reference%s (first: "%s" row %s → "%s"); nothing was changed.',
                            $table, count($violations), count($violations) === 1 ? '' : 's', $first['table'], $first['rowid'] ?? '?', $first['parent'],
                        ));
                    }
                }
            });
        } finally {
            if ($enforced && ! $nested) {
                $conn->execute('PRAGMA foreign_keys = ON');
            }
        }
    }

    /** CREATE TABLE of $table rebuilt from introspection, without $dropped (and the constraints that use them). */
    private function definitionWithout(string $table, array $dropped): string
    {
        $sql = $this->createSql($table);
        if (preg_match('/\bCHECK\s*\(|\bCOLLATE\s|\bDEFERRABLE\b|\bWITHOUT\s+ROWID\b|\)\s*STRICT\s*$/i', $sql) === 1) {
            throw new OrmException("Cannot rebuild SQLite table \"$table\" automatically: its definition uses CHECK / COLLATE / DEFERRABLE / WITHOUT ROWID / STRICT, which introspection would lose. Rebuild it with raw SQL.");
        }
        $info = $this->connection->fetchAll('PRAGMA table_xinfo(' . $this->q($table) . ')');
        $definitions = [];
        $primary = [];
        foreach ($info as $column) {
            if ((int) $column['hidden'] !== 0) {
                throw new OrmException("Cannot rebuild SQLite table \"$table\" automatically: generated column \"{$column['name']}\". Rebuild it with raw SQL.");
            }
            if ((int) $column['pk'] > 0) {
                $primary[(int) $column['pk']] = $column['name'];
            }
        }
        ksort($primary);
        $primary = array_values($primary);
        if (array_intersect($primary, $dropped) !== []) {
            $primary = []; // like PostgreSQL: dropping a key column drops the primary key constraint
        }
        $autoIncrement = count($primary) === 1 && preg_match('/\bAUTOINCREMENT\b/i', $sql) === 1;
        foreach ($info as $column) {
            if (in_array($column['name'], $dropped, true)) {
                continue;
            }
            $definition = $this->q($column['name']) . ($column['type'] !== '' ? ' ' . $column['type'] : '');
            if ($primary === [$column['name']]) {
                $definition .= ' PRIMARY KEY' . ($autoIncrement ? ' AUTOINCREMENT' : '');
            } elseif ((int) $column['notnull'] === 1) {
                $definition .= ' NOT NULL';
            }
            if ($column['dflt_value'] !== null) {
                $definition .= ' DEFAULT (' . $column['dflt_value'] . ')';
            }
            $definitions[] = $definition;
        }
        if (count($primary) > 1) {
            $definitions[] = 'PRIMARY KEY (' . implode(', ', array_map(fn ($c) => $this->q($c), $primary)) . ')';
        }
        foreach ($this->indexes($table) as $index) {
            if ($index['origin'] === 'u' && array_intersect($index['columns'], $dropped) === []) {
                $definitions[] = 'UNIQUE (' . implode(', ', array_map(fn ($c) => $this->q($c), $index['columns'])) . ')';
            }
        }
        foreach ($this->foreignKeys($table) as $fk) {
            if (array_intersect($fk['from'], $dropped) !== []) {
                continue;
            }
            $clause = 'FOREIGN KEY (' . implode(', ', array_map(fn ($c) => $this->q($c), $fk['from'])) . ') REFERENCES ' . $this->q($fk['table']);
            if (array_filter($fk['to'], fn ($c) => $c !== null) !== []) {
                $clause .= ' (' . implode(', ', array_map(fn ($c) => $this->q((string) $c), $fk['to'])) . ')';
            }
            foreach (['on_delete' => 'ON DELETE', 'on_update' => 'ON UPDATE'] as $key => $keyword) {
                if ($fk[$key] !== 'NO ACTION') {
                    $clause .= " $keyword " . $fk[$key];
                }
            }
            $definitions[] = $clause;
        }

        return 'CREATE TABLE ' . $this->q($table) . ' (' . implode(', ', $definitions) . ')';
    }

    /** Columns in the primary key, a UNIQUE constraint or a foreign key: ALTER TABLE DROP COLUMN refuses them. @return list<string> */
    private function constrainedColumns(string $table): array
    {
        $columns = [];
        foreach ($this->connection->fetchAll('PRAGMA table_info(' . $this->q($table) . ')') as $column) {
            if ((int) $column['pk'] > 0) {
                $columns[] = $column['name'];
            }
        }
        foreach ($this->indexes($table) as $index) {
            if ($index['origin'] !== 'c') {
                array_push($columns, ...$index['columns']);
            }
        }
        foreach ($this->foreignKeys($table) as $fk) {
            array_push($columns, ...$fk['from']);
        }

        return array_values(array_unique($columns));
    }

    /** @return list<array{name: string, origin: string, columns: list<string>, sql: ?string}> */
    private function indexes(string $table): array
    {
        $sql = array_column($this->connection->fetchAll("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = :t", ['t' => $table]), 'sql', 'name');
        $indexes = [];
        foreach ($this->connection->fetchAll('PRAGMA index_list(' . $this->q($table) . ')') as $index) {
            $columns = array_values(array_filter(array_column(
                $this->connection->fetchAll('PRAGMA index_info(' . $this->q($index['name']) . ')'),
                'name',
            ), fn ($c) => $c !== null));
            $indexes[] = ['name' => $index['name'], 'origin' => $index['origin'], 'columns' => $columns, 'sql' => $sql[$index['name']] ?? null];
        }

        return $indexes;
    }

    /** @return list<array{table: string, from: list<string>, to: list<?string>, on_update: string, on_delete: string}> */
    private function foreignKeys(string $table): array
    {
        $fks = [];
        foreach ($this->connection->fetchAll('PRAGMA foreign_key_list(' . $this->q($table) . ')') as $row) {
            $id = (int) $row['id'];
            $fks[$id] ??= ['table' => $row['table'], 'from' => [], 'to' => [], 'on_update' => strtoupper($row['on_update']), 'on_delete' => strtoupper($row['on_delete'])];
            $fks[$id]['from'][] = $row['from'];
            $fks[$id]['to'][] = $row['to'];
        }
        ksort($fks);

        return array_values($fks);
    }

    /** Tables (including $table itself) with a foreign key to $table. @return list<string> */
    private function referencingTables(string $table): array
    {
        $tables = [];
        foreach ($this->connection->fetchAll("SELECT name FROM sqlite_master WHERE type = 'table'") as $row) {
            foreach ($this->foreignKeys($row['name']) as $fk) {
                if ($fk['table'] === $table) {
                    $tables[] = $row['name'];
                    break;
                }
            }
        }

        return $tables;
    }

    private function createSql(string $table): string
    {
        $sql = $this->connection->fetchColumn("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :t", ['t' => $table]);
        if (! is_string($sql)) {
            throw new OrmException("Table \"$table\" does not exist.");
        }

        return $sql;
    }

    /** The CREATE TABLE statement with another table name. */
    private function renamed(string $createSql, string $name): string
    {
        $renamed = preg_replace('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"(?:[^"]|"")+"|`[^`]+`|\[[^\]]+\]|[^\s(]+)/i', 'CREATE TABLE ' . $this->q($name), $createSql, 1, $count);
        if ($renamed === null || $count !== 1) {
            throw new OrmException('Cannot parse CREATE TABLE statement: ' . $createSql);
        }

        return $renamed;
    }

    private function hasSequenceTable(): bool
    {
        return $this->connection->fetchOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'") !== null;
    }

    private function q(string $identifier): string
    {
        return $this->connection->quote($identifier);
    }
}
