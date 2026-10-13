<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

use Nesto\Orm\Connection;
use Nesto\Orm\Migration\Grammar;
use Nesto\Orm\Schema\Comparison\Comparator;
use Nesto\Orm\Schema\Table;
use Nesto\Prisma\Introspection\TypeMapper;

/**
 * Compares two DatabaseSchemas on the database of `$connection` (where the "from" schema lives — the
 * live database, or a shadow database holding the migration history) and returns the Plan that turns one into
 * the other: the shared Comparator diff for tables, plus PostgreSQL extensions (CREATE EXTENSION, ALTER
 * EXTENSION ... UPDATE TO / SET SCHEMA; never dropped) and enum types (CREATE TYPE, ALTER TYPE ...
 * ADD VALUE, the "_new" type swap when values are removed, DROP TYPE). Every table of "from" that "to" does
 * not have is dropped (Prisma manages the whole schema).
 */
final class Differ
{
    public function __construct(private readonly Connection $connection) {}

    public function plan(DatabaseSchema $from, DatabaseSchema $to): Plan
    {
        $q = fn (string $name) => $this->connection->quote($name);
        $literal = fn (string $value) => "'" . str_replace("'", "''", $value) . "'";
        $grammar = new Grammar($this->connection);
        $before = $after = [];
        $enumWarnings = [];
        $current = array_map(fn (Table $t) => $t->copy(), $from->tables);
        $altered = [];

        if ($this->connection->driver() === 'pgsql') {
            // extensions first: the types they bring (citext, hstore, ...) may be used by the tables
            foreach ($to->extensions as $name => $wanted) {
                $installed = $from->extensions[$name] ?? null;
                if ($installed === null) {
                    $before[] = 'CREATE EXTENSION IF NOT EXISTS ' . $q($name)
                        . ($wanted['schema'] !== null ? ' WITH SCHEMA ' . $q($wanted['schema']) : '')
                        . ($wanted['version'] !== null ? ' VERSION ' . $literal($wanted['version']) : '');
                    continue;
                }
                if ($wanted['version'] !== null && $installed['version'] !== null && $wanted['version'] !== $installed['version']) {
                    $before[] = 'ALTER EXTENSION ' . $q($name) . ' UPDATE TO ' . $literal($wanted['version']);
                }
                if ($wanted['schema'] !== null && $installed['schema'] !== null && $wanted['schema'] !== $installed['schema']) {
                    $before[] = 'ALTER EXTENSION ' . $q($name) . ' SET SCHEMA ' . $q($wanted['schema']);
                }
            }
            // extensions missing from the schema are left installed: dropping one would drop what depends on it
            foreach ($to->enums as $name => $values) {
                if (! isset($from->enums[$name])) {
                    $before[] = 'CREATE TYPE ' . $q($name) . ' AS ENUM (' . implode(', ', array_map($literal, $values)) . ')';
                    continue;
                }
                $old = $from->enums[$name];
                $added = array_values(array_diff($values, $old));
                $removed = array_values(array_diff($old, $values));
                if ($added === [] && $removed === []) {
                    continue;
                }
                $altered[$name] = $values;
                if ($removed === []) {
                    foreach ($added as $value) {
                        $position = array_search($value, $values, true);
                        $previous = null;
                        for ($i = $position - 1; $i >= 0; $i--) {
                            if (in_array($values[$i], $old, true)) {
                                $previous = $values[$i];
                                break;
                            }
                        }
                        $before[] = 'ALTER TYPE ' . $q($name) . ' ADD VALUE ' . $literal($value) . ($previous !== null ? ' AFTER ' . $literal($previous) : '');
                    }
                    continue;
                }
                // values removed: swap in a new type (rows still using a removed value make the cast fail)
                $enumWarnings[$name] = $removed;
                $new = $name . '_new';
                $before[] = 'CREATE TYPE ' . $q($new) . ' AS ENUM (' . implode(', ', array_map($literal, $values)) . ')';
                $restoreDefaults = [];
                foreach ($current as $table) {
                    foreach ($table->columns as $column) {
                        if ($column->enumName !== $name) {
                            continue;
                        }
                        $t = $q($table->name);
                        $c = $q($column->name);
                        if ($column->hasDefault && $column->default !== null) {
                            $before[] = "ALTER TABLE $t ALTER COLUMN $c DROP DEFAULT";
                            $wanted = ($to->tables[$table->name] ?? null)?->column($column->name);
                            if ($wanted !== null && $wanted->hasDefault && $wanted->default !== null && $wanted->enumName === $name) {
                                $restoreDefaults[] = "ALTER TABLE $t ALTER COLUMN $c SET DEFAULT " . $grammar->defaultSql($wanted, $wanted->default);
                                $column->default = $wanted->default;
                            } else {
                                $column->hasDefault = false;
                                $column->default = null;
                            }
                        }
                        $before[] = $column->array
                            ? "ALTER TABLE $t ALTER COLUMN $c TYPE " . $q($new) . "[] USING ($c::text[]::" . $q($new) . '[])'
                            : "ALTER TABLE $t ALTER COLUMN $c TYPE " . $q($new) . " USING ($c::text::" . $q($new) . ')';
                    }
                }
                $before[] = 'ALTER TYPE ' . $q($name) . ' RENAME TO ' . $q($name . '_old');
                $before[] = 'ALTER TYPE ' . $q($new) . ' RENAME TO ' . $q($name);
                $before[] = 'DROP TYPE ' . $q($name . '_old');
                array_push($before, ...$restoreDefaults);
            }
            foreach ($from->enums as $name => $values) {
                if (! isset($to->enums[$name])) {
                    $after[] = 'DROP TYPE ' . $q($name);
                }
            }
            // the comparator must not see enum label changes handled above
            foreach ($current as $table) {
                foreach ($table->columns as $column) {
                    if ($column->enumName !== null && isset($altered[$column->enumName])) {
                        $column->values = $altered[$column->enumName];
                    }
                }
            }
        }

        if ($this->connection->driver() === 'sqlite') {
            $this->sqliteEquivalences($current, $to);
        }
        $comparator = new Comparator($grammar);
        $diff = $comparator->compare($current, $to->tables, array_keys($current));
        if ($this->connection->driver() !== 'sqlite') {
            // like Prisma: new tables first, their foreign keys afterwards (-- AddForeignKey)
            foreach ($diff->created as $i => $table) {
                if ($table->foreignKeys === []) {
                    continue;
                }
                $bare = $table->copy();
                $bare->foreignKeys = [];
                $diff->created[$i] = $bare;
                $keys = new \Nesto\Orm\Schema\Comparison\TableDiff($table->name, $bare, $table);
                $keys->addedForeignKeys = $table->foreignKeys;
                $diff->altered[] = $keys;
            }
        }
        if ($altered !== []) {
            // changed enum columns: TableAlterer compares with the live column, whose labels are still the old ones
            foreach ($diff->altered as $tableDiff) {
                foreach ($tableDiff->changedColumns as $i => [$currentColumn, $wanted]) {
                    if ($wanted->enumName !== null && isset($altered[$wanted->enumName]) && isset($from->tables[$tableDiff->table])) {
                        $live = $from->tables[$tableDiff->table]->column($currentColumn->name);
                        if ($live !== null && $live->enumName === $wanted->enumName) {
                            $copy = clone $wanted;
                            $copy->values = $live->values;
                            $tableDiff->changedColumns[$i] = [$currentColumn, $copy];
                        }
                    }
                }
            }
        }

        return new Plan($diff, $before, $after, $enumWarnings, $from, $to);
    }

    /**
     * SQLite keeps declared types verbatim and foreign keys are often unnamed: like Prisma, compare column
     * types by family (VARCHAR(50) is a String column like TEXT) and do not compare the names of unnamed
     * foreign keys.
     *
     * @param array<string, Table> $current
     */
    private function sqliteEquivalences(array $current, DatabaseSchema $to): void
    {
        foreach ($current as $name => $table) {
            $wanted = $to->tables[$name] ?? null;
            if ($wanted === null) {
                continue;
            }
            foreach ($table->columns as $column) {
                $target = $wanted->column($column->name);
                if ($target === null || $target->sqlType === null || $column->rawType === null || strcasecmp($target->sqlType, $column->rawType) === 0) {
                    continue;
                }
                $probe = new \Nesto\Orm\Schema\Column($target->name, $target->type);
                $probe->rawType = $target->sqlType;
                $family = TypeMapper::map($column, 'sqlite')['type'];
                if ($family !== 'Unsupported' && $family === TypeMapper::map($probe, 'sqlite')['type']) {
                    $column->rawType = $target->sqlType;
                }
            }
            foreach ($table->foreignKeys as $i => $fk) {
                if ($fk->name !== null) {
                    continue;
                }
                foreach ($wanted->foreignKeys as $candidate) {
                    if (\Nesto\Orm\Schema\Normalizer::sameColumns($fk->columns, $candidate->columns) && strcasecmp($fk->referencedTable, $candidate->referencedTable) === 0
                        && \Nesto\Orm\Schema\Normalizer::sameColumns($fk->referencedColumns, $candidate->referencedColumns)) {
                        $table->foreignKeys[$i] = new \Nesto\Orm\Schema\ForeignKey($fk->columns, $fk->referencedTable, $fk->referencedColumns, $fk->onDelete, $fk->onUpdate, $candidate->name, $candidate->explicitName);
                        break;
                    }
                }
            }
        }
    }

    /** True when the two schemas are the same (nothing to migrate). */
    public function same(DatabaseSchema $from, DatabaseSchema $to): bool
    {
        return $this->plan($from, $to)->isEmpty();
    }
}
