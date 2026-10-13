<?php

declare(strict_types=1);

namespace Nesto\Orm\Migration;

use Nesto\Orm\Schema\Check;
use Nesto\Orm\Schema\Column;
use Nesto\Orm\Schema\ForeignKey;
use Nesto\Orm\Schema\Index;
use Nesto\Orm\Schema\Introspection\Introspector;
use Nesto\Orm\Schema\Normalizer;
use Nesto\Orm\Schema\Introspection\PostgresIntrospector;
use Nesto\Orm\Schema\Introspection\SqliteIntrospector;
use Nesto\Orm\Schema\Table;
use Nesto\Orm\OrmException;

/**
 * Applies a Blueprint to an existing table (Schema::table()): drops, renames, adds and changes columns,
 * keys, indexes and checks. Constraints given by columns (`dropForeign(['user_id'])`) are looked up in the
 * live schema, so migrations do not depend on the names a database chose.
 *
 * MySQL / MariaDB / PostgreSQL run ALTER statements in this order: drop foreign keys, checks, indexes and the
 * primary key; rename, add, change and drop columns; add the primary key, indexes, checks and foreign keys.
 * SQLite runs what ALTER TABLE supports directly and rebuilds the table (SqliteTableRebuilder) for the rest.
 */
final class TableAlterer
{
    private readonly Grammar $grammar;

    public function __construct(private readonly Schema $schema)
    {
        $this->grammar = $schema->grammar();
    }

    public function alter(Blueprint $blueprint): void
    {
        $current = Introspector::for($this->schema->getConnection())->table($blueprint->table)
            ?? throw new OrmException("Cannot alter table \"{$blueprint->table}\": it does not exist.");
        $blueprint = self::withInheritedAttributes($blueprint, $current);
        if ($this->schema->getConnection()->driver() === 'sqlite') {
            $this->alterSqlite($blueprint, $current);

            return;
        }
        foreach ($this->statements($blueprint, $current) as $sql) {
            $this->schema->statement($sql);
        }
    }

    /**
     * The blueprint with every ->change() column completed from the live one (ColumnDefinition::inherit()): what a
     * hand-written change does not state is kept. A column renamed in the same blueprint is found by its old name.
     */
    private static function withInheritedAttributes(Blueprint $blueprint, Table $current): Blueprint
    {
        $renames = [];
        foreach ($blueprint->commands as $command) {
            if ($command['type'] === 'renameColumn') {
                $renames[strtolower((string) $command['to'])] = (string) $command['from'];
            }
        }
        $copy = clone $blueprint;
        foreach ($copy->columns as $i => $column) {
            if ($column->change && ($live = $current->column($renames[strtolower($column->name)] ?? $column->name)) !== null) {
                $copy->columns[$i] = $column->inherit($live);
            }
        }

        return $copy;
    }

    /** @return list<string> */
    private function statements(Blueprint $blueprint, Table $current): array
    {
        $g = $this->grammar;
        $table = $blueprint->table;
        $connection = $this->schema->getConnection();
        $statements = [];
        $renames = [];
        foreach ($blueprint->commands as $command) {
            if ($command['type'] === 'renameColumn') {
                $renames[strtolower($command['to'])] = $command['from'];
            }
        }

        $droppedForeignKeys = array_map(fn (array $command) => $this->findForeignKey($current, $command), $this->commands($blueprint, 'dropForeign'));
        foreach ($droppedForeignKeys as $fk) {
            $statements[] = $g->compileDropForeign($table, (string) $fk->name);
            // InnoDB keeps the index it created for the foreign key: it goes with it
            $index = $connection->driver() === 'mysql' ? $this->ownIndexOf($current, $fk, $droppedForeignKeys) : null;
            if ($index !== null) {
                $statements[] = $g->compileDropIndex($table, $index);
            }
        }
        foreach ($this->commands($blueprint, 'dropCheck') as $command) {
            $statements[] = $g->compileDropCheck($table, (string) $this->findCheck($current, (string) $command['name'])->name);
        }
        foreach ($blueprint->commands as $command) {
            if ($command['type'] === 'dropIndex' || $command['type'] === 'dropUnique') {
                $statements[] = $g->compileDropIndex($table, $this->findIndex($current, $command));
            }
        }
        if ($this->commands($blueprint, 'dropPrimary') !== [] && $current->primaryKey !== []) {
            $name = $connection->driver() === 'pgsql' ? (new PostgresIntrospector($connection))->primaryKeyName($table) : null;
            // MySQL refuses to drop the key of an AUTO_INCREMENT column: remove the auto-increment first
            foreach ($current->primaryKey as $columnName) {
                $column = $current->column($columnName);
                if ($column !== null && $column->autoIncrement && $connection->driver() === 'mysql') {
                    $plain = clone $column;
                    $plain->autoIncrement = false;
                    $plain->rawType = null;
                    array_push($statements, ...$g->compileChangeColumn($table, $plain, $column));
                }
            }
            $statements[] = $g->compileDropPrimary($table, $name);
        }
        foreach ($this->commands($blueprint, 'renameColumn') as $command) {
            $statements[] = $g->compileRenameColumn($table, (string) $command['from'], (string) $command['to']);
        }
        $added = new Blueprint($table);
        foreach ($blueprint->columns as $column) {
            if (! $column->change) {
                $added->add($column);
            }
        }
        if ($added->columns !== []) {
            array_push($statements, ...$g->compileAddColumns($added));
        }
        foreach ($blueprint->columns as $column) {
            if (! $column->change) {
                continue;
            }
            $from = $current->column($renames[strtolower($column->name)] ?? $column->name)
                ?? throw new OrmException("Cannot change column \"{$column->name}\": table \"$table\" has no such column.");
            array_push($statements, ...$this->changeColumn($table, $column, $from, $this->commands($blueprint, 'dropPrimary') !== []));
        }
        foreach ($this->commands($blueprint, 'dropColumn') as $command) {
            foreach ($command['columns'] ?? [] as $columnName) {
                $statements[] = $g->compileDropColumn($table, $columnName);
            }
        }
        if ($blueprint->primaryKeys !== []) {
            $statements[] = $g->compileAddPrimary($table, $blueprint->primaryKeys, $blueprint->primaryKeyName);
            // an auto-increment column that becomes the key again
            foreach ($blueprint->columns as $column) {
                if ($column->change && $column->autoIncrement && $connection->driver() === 'mysql' && in_array($column->name, $blueprint->primaryKeys, true)) {
                    $statements[] = 'ALTER TABLE ' . $connection->quote($table) . ' MODIFY ' . $g->columnSql($column, inlinePrimary: false, forChange: true);
                }
            }
        }
        foreach ($blueprint->indexes as $index) {
            $statements[] = $g->compileCreateIndex($table, self::indexSchema($table, $index));
        }
        foreach ($blueprint->checks as $check) {
            $statements[] = $g->compileAddCheck($table, new Check($check['expression'], $check['name'] ?? Check::defaultName($table, $check['expression'])));
        }
        foreach ($blueprint->foreignKeys as $fk) {
            if ($fk->referencedTable !== null) {
                $statements[] = $g->compileAddForeignKey($table, $fk);
            }
        }

        return $statements;
    }

    /** @return list<string> */
    private function changeColumn(string $table, Column $to, Column $from, bool $primaryDropped): array
    {
        $connection = $this->schema->getConnection();
        if (self::generation($from) !== self::generation($to)) {
            // a generated column's definition cannot be altered portably: drop it and add it again (its values
            // are derived; a plain column turning generated loses its own values, like in TypeORM)
            $added = new Blueprint($table);
            $added->add(ColumnDefinition::of($to)->change(false));

            return [$this->grammar->compileDropColumn($table, $from->name), ...$this->grammar->compileAddColumns($added)];
        }
        if ($connection->driver() === 'mysql' && $to->autoIncrement && (! $from->primary || $primaryDropped)) {
            $to = clone $to;
            $to->autoIncrement = false; // AUTO_INCREMENT needs the key: it is restored after ADD PRIMARY KEY
        }
        if ($connection->driver() === 'pgsql' && $to->enumName !== null && $from->enumName === $to->enumName
            && array_map('strval', $from->values) !== array_map('strval', $to->values)) {
            // new labels for a native enum: swap in a re-created type
            $q = fn (string $s) => $connection->quote($s);
            $old = $to->enumName . '_old';
            $statements = ['ALTER TYPE ' . $q($to->enumName) . ' RENAME TO ' . $q($old)];
            array_push($statements, ...$this->grammar->enumTypeStatements([$to]));
            $statements[] = 'ALTER TABLE ' . $q($table) . ' ALTER COLUMN ' . $q($to->name) . ' DROP DEFAULT';
            $statements[] = 'ALTER TABLE ' . $q($table) . ' ALTER COLUMN ' . $q($to->name) . ' TYPE ' . $q($to->enumName) . ' USING ' . $q($to->name) . '::text::' . $q($to->enumName);
            $fromPlain = clone $from;
            $fromPlain->hasDefault = false;
            $fromPlain->default = null;
            $fromPlain->rawType = null;
            foreach ($this->grammar->compileChangeColumn($table, $to, $fromPlain) as $sql) {
                if (! str_contains($sql, ' TYPE ') && ! str_contains($sql, 'DROP DEFAULT')) {
                    $statements[] = $sql;
                }
            }
            $statements[] = 'DROP TYPE ' . $q($old);

            return $statements;
        }
        if ($connection->driver() !== 'pgsql') {
            return $this->grammar->compileChangeColumn($table, $to, $from);
        }
        // PostgreSQL alters each aspect separately: work out which ones differ, in canonical form
        $fromType = Normalizer::type('pgsql', $from->rawType ?? $this->grammar->typeSql($from))[0];
        $toType = Normalizer::type('pgsql', $this->grammar->typeSql($to))[0];
        $changes = [
            'type' => $fromType !== $toType || ($to->collation !== null && strcasecmp($to->collation, (string) $from->collation) !== 0),
            'nullable' => $from->nullable !== $to->nullable,
            'default' => Normalizer::defaultValue($from, $fromType) !== Normalizer::defaultValue($to, $toType),
            'comment' => ($from->comment ?? '') !== ($to->comment ?? ''),
        ];

        return $this->grammar->compileChangeColumn($table, $to, $from, $changes);
    }

    /** Comparable generation of a column: null for a plain column, else kind + normalized expression. */
    private static function generation(Column $column): ?string
    {
        return $column->generatedType !== null && $column->asExpression !== null
            ? strtoupper($column->generatedType) . ':' . Normalizer::generationExpression($column->asExpression)
            : null;
    }

    private function alterSqlite(Blueprint $blueprint, Table $current): void
    {
        if ($this->sqliteCanAlterInPlace($blueprint, $current)) {
            $g = $this->grammar;
            foreach ($blueprint->commands as $command) {
                if ($command['type'] === 'dropIndex' || $command['type'] === 'dropUnique') {
                    $this->schema->statement($g->compileDropIndex($blueprint->table, $this->findIndex($current, $command)));
                }
            }
            foreach ($this->commands($blueprint, 'renameColumn') as $command) {
                $this->schema->statement($g->compileRenameColumn($blueprint->table, (string) $command['from'], (string) $command['to']));
            }
            $added = new Blueprint($blueprint->table);
            foreach ($blueprint->columns as $column) {
                $added->add($column);
            }
            $added->indexes = $blueprint->indexes;
            foreach ($added->columns !== [] || $added->indexes !== [] ? $g->compileAddColumns($added) : [] as $sql) {
                $this->schema->statement($sql);
            }
            foreach ($this->commands($blueprint, 'dropColumn') as $command) {
                foreach ($command['columns'] ?? [] as $columnName) {
                    $this->schema->statement($g->compileDropColumn($blueprint->table, $columnName));
                }
            }

            return;
        }

        [$new, $copy] = $this->applyToSchema($blueprint, $current);
        $introspector = new SqliteIntrospector($this->schema->getConnection());
        $introspector->table($blueprint->table);
        if (isset($introspector->unsupported[$blueprint->table])) {
            throw new OrmException("Cannot rebuild SQLite table \"{$blueprint->table}\" automatically: {$introspector->unsupported[$blueprint->table]}. Alter it with raw SQL.");
        }
        (new SqliteTableRebuilder($this->schema->getConnection()))->rebuildTo($new, $copy, $this->grammar, $this->schema);
    }

    /** ALTER TABLE in SQLite can add nullable / defaulted columns, rename and drop unconstrained columns, and manage plain indexes. */
    private function sqliteCanAlterInPlace(Blueprint $blueprint, Table $current): bool
    {
        if ($blueprint->primaryKeys !== [] || $blueprint->checks !== [] || $blueprint->foreignKeys !== []) {
            return false;
        }
        foreach ($blueprint->columns as $column) {
            if ($column->change || $column->primary || (! $column->nullable && ! ($column->hasDefault && $column->default !== null))
                || $column->generatedType === 'STORED') { // ADD COLUMN cannot add STORED generated columns
                return false;
            }
        }
        $constrained = $current->primaryKey;
        foreach ($current->indexes as $index) {
            if ($index->constraint || $index->where !== null) {
                array_push($constrained, ...$index->columns);
            }
        }
        foreach ($current->foreignKeys as $fk) {
            array_push($constrained, ...$fk->columns);
        }
        $constrained = array_map('strtolower', $constrained);
        foreach ($blueprint->commands as $command) {
            switch ($command['type']) {
                case 'renameColumn':
                    break;
                case 'dropIndex':
                case 'dropUnique':
                    if ($this->findIndex($current, $command)->constraint) {
                        return false;
                    }
                    break;
                case 'dropColumn':
                    if ($current->checks !== [] || array_intersect(array_map('strtolower', $command['columns'] ?? []), $constrained) !== []) {
                        return false;
                    }
                    // indexes on the column must go first (SQLite refuses to drop an indexed column)
                    foreach ($current->indexes as $index) {
                        if (array_intersect(array_map('strtolower', $index->columns), array_map('strtolower', $command['columns'] ?? [])) !== []
                            && ! $this->dropsIndex($blueprint, $index)) {
                            return false;
                        }
                    }
                    break;
                default:
                    return false;
            }
        }

        return true;
    }

    private function dropsIndex(Blueprint $blueprint, Index $index): bool
    {
        foreach ($blueprint->commands as $command) {
            if (($command['type'] === 'dropIndex' || $command['type'] === 'dropUnique') && $this->matchesIndex($index, $command)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The table definition after the blueprint, and the copy expressions (new column => SQL over the old table).
     *
     * @return array{0: Table, 1: array<string, string>}
     */
    public function applyToSchema(Blueprint $blueprint, Table $current): array
    {
        $connection = $this->schema->getConnection();
        $new = $current->copy();
        $copy = [];
        foreach ($new->columns as $name => $column) {
            $copy[$name] = $connection->quote($name);
        }
        $renameIn = function (array $columns, string $from, string $to): array {
            return array_map(fn (string $c) => strcasecmp($c, $from) === 0 ? $to : $c, $columns);
        };
        $dropped = [];
        foreach ($blueprint->commands as $command) {
            switch ($command['type']) {
                case 'dropForeign':
                    $fk = $this->findForeignKey($new, $command);
                    $new->foreignKeys = array_values(array_filter($new->foreignKeys, fn (ForeignKey $f) => $f !== $fk));
                    break;
                case 'dropCheck':
                    $check = $this->findCheck($new, (string) $command['name']);
                    $new->checks = array_values(array_filter($new->checks, fn (Check $c) => $c !== $check));
                    break;
                case 'dropIndex':
                case 'dropUnique':
                    $index = $this->findIndex($new, $command);
                    $new->indexes = array_values(array_filter($new->indexes, fn (Index $i) => $i !== $index));
                    break;
                case 'dropPrimary':
                    $new->primaryKey = [];
                    foreach ($new->columns as $column) {
                        $column->primary = false;
                        $column->autoIncrement = false;
                    }
                    break;
                case 'renameColumn':
                    $from = (string) $command['from'];
                    $to = (string) $command['to'];
                    $column = $new->column($from) ?? throw new OrmException("Cannot rename column \"$from\": table \"{$blueprint->table}\" has no such column.");
                    $columns = [];
                    foreach ($new->columns as $name => $definition) {
                        if ($definition === $column) {
                            $renamed = $definition->copyAs($definition::class, $to);
                            $columns[$to] = $renamed;
                            $copy[$to] = $copy[$name];
                            unset($copy[$name]);
                        } else {
                            $columns[$name] = $definition;
                        }
                    }
                    $new->columns = $columns;
                    $new->primaryKey = $renameIn($new->primaryKey, $from, $to);
                    $new->indexes = array_map(fn (Index $i) => new Index($renameIn($i->columns, $from, $to), $i->unique, $i->name, $i->explicitName, $i->where, $i->constraint, $i->implicit), $new->indexes);
                    $new->foreignKeys = array_map(fn (ForeignKey $f) => new ForeignKey($renameIn($f->columns, $from, $to), $f->referencedTable, $f->referencedColumns, $f->onDelete, $f->onUpdate, $f->name, $f->explicitName), $new->foreignKeys);
                    break;
                case 'dropColumn':
                    foreach ($command['columns'] ?? [] as $columnName) {
                        $column = $new->column($columnName) ?? throw new OrmException("Cannot drop column \"$columnName\": table \"{$blueprint->table}\" has no such column.");
                        unset($new->columns[$column->name], $copy[$column->name]);
                        $dropped[] = strtolower($column->name);
                    }
                    break;
            }
        }
        if ($dropped !== []) {
            // like PostgreSQL: indexes, keys and foreign keys using a dropped column go with it
            $uses = fn (array $columns) => array_intersect(array_map('strtolower', $columns), $dropped) !== [];
            $new->indexes = array_values(array_filter($new->indexes, fn (Index $i) => ! $uses($i->columns)));
            $new->foreignKeys = array_values(array_filter($new->foreignKeys, fn (ForeignKey $f) => ! $uses($f->columns)));
            if ($uses($new->primaryKey)) {
                $new->primaryKey = [];
            }
        }
        $added = $blueprint->toTable();
        foreach ($blueprint->columns as $column) {
            $definition = $added->columns[$column->name];
            if ($column->change) {
                $existing = $new->column($column->name) ?? throw new OrmException("Cannot change column \"{$column->name}\": table \"{$blueprint->table}\" has no such column.");
                $definition->primary = $existing->primary;
                $definition->autoIncrement = $definition->autoIncrement || ($existing->primary && $existing->autoIncrement && $column->type === $existing->type);
                $columns = [];
                foreach ($new->columns as $name => $old) {
                    $columns[$old === $existing ? $column->name : $name] = $old === $existing ? $definition : $old;
                }
                $new->columns = $columns;
                if (! $definition->nullable && ! ($definition->hasDefault && $definition->default !== null) && $existing->nullable) {
                    $fill = $this->grammar->implicitDefault($definition) ?? "''";
                    $copy[$column->name] = 'COALESCE(' . $copy[$column->name] . ', ' . $fill . ')';
                }
                continue;
            }
            if ($new->column($column->name) !== null) {
                throw new OrmException("Cannot add column \"{$column->name}\": table \"{$blueprint->table}\" already has it.");
            }
            $new->columns[$column->name] = $definition;
            if (! $definition->nullable && ! ($definition->hasDefault && $definition->default !== null) && ! $column->primary) {
                // existing rows need a value for a NOT NULL column without default
                $copy[$column->name] = $this->grammar->implicitDefault($definition) ?? "''";
            }
        }
        if ($blueprint->primaryKeys !== []) {
            $new->primaryKey = $blueprint->primaryKeys;
            $new->primaryKeyName = $blueprint->primaryKeyName;
        } elseif ($added->primaryKey !== []) {
            $new->primaryKey = $added->primaryKey;
        }
        foreach ($new->columns as $column) {
            $column->primary = in_array($column->name, $new->primaryKey, true);
            if ($column->primary) {
                $column->nullable = false;
            }
        }
        array_push($new->indexes, ...$added->indexes);
        array_push($new->foreignKeys, ...$added->foreignKeys);
        array_push($new->checks, ...$added->checks);
        foreach ($new->columns as $name => $column) {
            if ($column->generatedType !== null) {
                unset($copy[$name]); // computed by the new table
            }
        }

        return [$new, $copy];
    }

    // ---- lookups --------------------------------------------------------------------------------

    /** The index MySQL created for $fk, unless another remaining foreign key still needs it. @param list<ForeignKey> $dropped */
    private function ownIndexOf(Table $table, ForeignKey $fk, array $dropped): ?Index
    {
        foreach ($table->foreignKeys as $other) {
            if (! in_array($other, $dropped, true) && Normalizer::sameColumns(array_slice($other->columns, 0, count($fk->columns)), $fk->columns)) {
                return null;
            }
        }
        foreach ($table->indexes as $index) {
            if ($index->implicit && $index->name !== null && Normalizer::sameColumns($index->columns, $fk->columns)
                && ($index->name === $fk->name || $index->name === $fk->columns[0])) {
                return $index;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function commands(Blueprint $blueprint, string $type): array
    {
        return array_values(array_filter($blueprint->commands, fn (array $c) => $c['type'] === $type));
    }

    /** @param array<string, mixed> $command */
    private function findForeignKey(Table $table, array $command): ForeignKey
    {
        foreach ($table->foreignKeys as $fk) {
            if (isset($command['name']) ? ($fk->name !== null && strcasecmp($fk->name, $command['name']) === 0) : Normalizer::sameColumns($fk->columns, $command['columns'] ?? [])) {
                return $fk;
            }
        }
        // a single column name given as a string: also accept it as the constrained column
        if (isset($command['name'])) {
            foreach ($table->foreignKeys as $fk) {
                if (Normalizer::sameColumns($fk->columns, [$command['name']])) {
                    return $fk;
                }
            }
        }

        throw new OrmException(sprintf('Table "%s" has no foreign key %s.', $table->name, self::describe($command)));
    }

    /** @param array<string, mixed> $command */
    private function findIndex(Table $table, array $command): Index
    {
        $unique = $command['type'] === 'dropUnique';
        $candidates = array_values(array_filter($table->indexes, fn (Index $i) => $this->matchesIndex($i, $command)));
        usort($candidates, fn (Index $a, Index $b) => (int) ($b->unique === $unique) <=> (int) ($a->unique === $unique));
        if ($candidates !== []) {
            return $candidates[0];
        }

        throw new OrmException(sprintf('Table "%s" has no %s %s.', $table->name, $unique ? 'unique index / constraint' : 'index', self::describe($command)));
    }

    /** @param array<string, mixed> $command */
    private function matchesIndex(Index $index, array $command): bool
    {
        if (isset($command['name'])) {
            return $index->name !== null && strcasecmp($index->name, $command['name']) === 0;
        }

        return Normalizer::sameColumns($index->columns, $command['columns'] ?? []);
    }

    private function findCheck(Table $table, string $nameOrExpression): Check
    {
        foreach ($table->checks as $check) {
            if (($check->name !== null && strcasecmp($check->name, $nameOrExpression) === 0)
                || Normalizer::expression($check->expression) === Normalizer::expression($nameOrExpression)) {
                return $check;
            }
        }

        throw new OrmException("Table \"{$table->name}\" has no check constraint \"$nameOrExpression\".");
    }

    /** @param array<string, mixed> $command */
    private static function describe(array $command): string
    {
        return isset($command['name']) ? '"' . $command['name'] . '"' : 'on (' . implode(', ', $command['columns'] ?? []) . ')';
    }

    /** @param array{columns: list<string>, unique: bool, name: ?string, where?: ?string, sorts?: array<string, string>, lengths?: array<string, int>, using?: ?string} $index */
    private static function indexSchema(string $table, array $index): Index
    {
        return new Index(
            $index['columns'],
            $index['unique'],
            $index['name'] ?? Grammar::indexName($table, $index['columns'], $index['unique']),
            $index['name'] !== null,
            $index['where'] ?? null,
            sorts: $index['sorts'] ?? [],
            lengths: $index['lengths'] ?? [],
            using: $index['using'] ?? null,
        );
    }
}
