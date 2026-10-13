<?php

declare(strict_types=1);

namespace Nesto\Orm\Schema\Comparison;

use Nesto\Orm\Schema\Check;
use Nesto\Orm\Schema\ForeignKey;
use Nesto\Orm\Schema\Index;
use Nesto\Orm\Schema\Normalizer;
use Nesto\Orm\Schema\Table;
use Nesto\Orm\Schema\View;
use Nesto\Orm\Schema\Column;
use Nesto\Orm\Migration\Grammar;
use Nesto\Orm\Types\ColumnType;
use Nesto\Orm\Types\TypeRegistry;

/**
 * Compares two schemas (normally: what the database has → what the entities want) and returns the
 * SchemaDiff that turns the first into the second. Everything is compared in canonical form (see
 * Normalizer), so a database created from the entities compares equal to them on every platform.
 *
 *   $diff = (new Comparator($grammar))->compare($actualTables, $wantedTables);
 *   $diff->isEmpty();
 *
 * Tables present only in `$from` are dropped only when listed in `$dropTables` (synchronization never drops
 * tables it does not know, like TypeORM; reverse diffs drop the tables the forward diff created).
 */
final class Comparator
{
    private readonly string $platform;

    public function __construct(private readonly Grammar $grammar)
    {
        $this->platform = $grammar->connection()->platform();
    }

    /**
     * @param array<string, Table> $from  current tables
     * @param array<string, Table> $to    wanted tables (in creation / dependency order)
     * @param list<string>               $dropTables tables of $from that must be dropped when absent from $to
     */
    public function compare(array $from, array $to, array $dropTables = []): SchemaDiff
    {
        $diff = new SchemaDiff($this->platform);
        foreach ($to as $name => $table) {
            $current = $this->find($from, $name);
            if ($current === null) {
                $diff->created[] = $table;
                continue;
            }
            $tableDiff = $this->compareTable($current, $table);
            if (! $tableDiff->isEmpty()) {
                $diff->altered[] = $tableDiff;
            }
        }
        foreach (array_reverse($dropTables) as $name) {
            if ($this->find($to, $name) === null && ($table = $this->find($from, $name)) !== null) {
                $diff->dropped[] = $table;
            }
        }

        return $diff;
    }

    /**
     * Add the view changes to $diff: views of $to missing from $from are created; views whose expression (or
     * materialization) differs — or whose recorded expression is unknown — are dropped and recreated, and so
     * are the views depending on them (dependsOn) and, with $touchedTables, the views whose expression
     * names one of those (altered) tables (PostgreSQL refuses to alter columns a view uses). Views only in
     * $from are dropped when listed in $dropViews. Drops come dependents-first, creations dependencies-first.
     *
     * @param array<string, View> $from current views
     * @param array<string, View> $to   wanted views
     * @param list<string>        $dropViews
     * @param list<string>        $touchedTables
     */
    public function compareViews(SchemaDiff $diff, array $from, array $to, array $dropViews = [], array $touchedTables = []): SchemaDiff
    {
        $recreate = [];
        foreach ($to as $name => $view) {
            $current = $from[$name] ?? null;
            if ($current === null) {
                $recreate[$name] = true;
                continue;
            }
            if (! $current->sameAs($view) || self::mentionsAny((string) $view->expression, $touchedTables)) {
                $recreate[$name] = true;
            }
        }
        // views (re)created because something they select from is
        do {
            $added = false;
            foreach ($to as $name => $view) {
                if (! isset($recreate[$name]) && isset($from[$name]) && array_intersect($view->dependsOn, array_keys($recreate)) !== []) {
                    $recreate[$name] = true;
                    $added = true;
                }
            }
        } while ($added);

        // introspected views know no dependencies: take them from whichever side declares them
        $dependencies = [];
        foreach ([...array_values($to), ...array_values($from)] as $view) {
            $dependencies[$view->name] = array_values(array_unique([...($dependencies[$view->name] ?? []), ...$view->dependsOn]));
        }
        $ordered = self::dependencyOrder($dependencies);
        $drop = [];
        foreach (array_keys($from) as $name) {
            if (isset($recreate[$name]) || (! isset($to[$name]) && in_array($name, $dropViews, true))) {
                $drop[$name] = true;
            }
        }
        foreach (array_reverse($ordered) as $name) { // dependents first
            if (isset($drop[$name])) {
                $diff->droppedViews[] = $from[$name];
            }
        }
        foreach ($ordered as $name) {
            if (isset($recreate[$name], $to[$name]) && $to[$name]->expression !== null) {
                $diff->createdViews[] = $to[$name];
            }
        }

        return $diff;
    }

    /** @param array<string, list<string>> $dependencies view => views it selects from @return list<string> names, each after its dependencies */
    private static function dependencyOrder(array $dependencies): array
    {
        $ordered = [];
        $visit = function (string $name, array $path) use (&$visit, &$ordered, $dependencies): void {
            if (in_array($name, $ordered, true) || ! isset($dependencies[$name]) || in_array($name, $path, true)) {
                return;
            }
            foreach ($dependencies[$name] as $dependency) {
                $visit($dependency, [...$path, $name]);
            }
            $ordered[] = $name;
        };
        foreach (array_keys($dependencies) as $name) {
            $visit((string) $name, []);
        }

        return $ordered;
    }

    /** @param list<string> $tables */
    private static function mentionsAny(string $expression, array $tables): bool
    {
        foreach ($tables as $table) {
            if (preg_match('/(?<![\w$])' . preg_quote($table, '/') . '(?![\w$])/i', $expression) === 1) {
                return true;
            }
        }

        return false;
    }

    public function compareTable(Table $from, Table $to): TableDiff
    {
        $diff = new TableDiff($to->name, $from, $to);

        // columns
        foreach ($to->columns as $name => $column) {
            $current = $from->column($name);
            if ($current === null) {
                $diff->addedColumns[] = $column;
            } elseif ($this->columnChanged($current, $column, $from)) {
                $diff->changedColumns[] = [$current, $column];
            }
        }
        foreach ($from->columns as $name => $column) {
            if ($to->column($name) === null) {
                $diff->droppedColumns[] = $column;
            }
        }

        // primary key
        if (! Normalizer::sameColumns($from->primaryKey, $to->primaryKey)
            || ($this->platform === 'pgsql' && $to->primaryKey !== [] && $to->primaryKeyName !== null && $from->primaryKeyName !== null
                && strcasecmp($to->primaryKeyName, $from->primaryKeyName) !== 0)) {
            $diff->primaryKey = [$from->primaryKey, $to->primaryKey];
        }

        // foreign keys
        $unmatched = $from->foreignKeys;
        foreach ($to->foreignKeys as $wanted) {
            $match = $this->takeMatch($unmatched, fn (ForeignKey $fk) => $this->sameForeignKey($fk, $wanted));
            if ($match === null) {
                $diff->addedForeignKeys[] = $wanted;
            }
        }
        foreach ($unmatched as $fk) {
            $diff->droppedForeignKeys[] = $fk;
        }

        // indexes and unique constraints
        $unmatched = $from->indexes;
        $wantedIndexes = $to->indexes;
        usort($wantedIndexes, fn (Index $a, Index $b) => (int) $b->explicitName <=> (int) $a->explicitName); // named ones pick first
        foreach ($wantedIndexes as $wanted) {
            $match = $this->takeMatch($unmatched, fn (Index $index) => $this->sameIndex($index, $wanted));
            if ($match === null && ! $wanted->implicit) {
                $diff->addedIndexes[] = $wanted;
            }
        }
        foreach ($unmatched as $index) {
            if (! $index->implicit) { // the database's own indexes (MySQL foreign-key indexes) are left to it
                $diff->droppedIndexes[] = $index;
            }
        }

        // checks
        $unmatched = $from->checks;
        foreach ($to->checks as $wanted) {
            $match = $this->takeMatch($unmatched, fn (Check $check) => $check->name !== null && $wanted->name !== null
                ? strcasecmp($check->name, $wanted->name) === 0
                : Normalizer::expression($check->expression) === Normalizer::expression($wanted->expression));
            if ($match === null) {
                $diff->addedChecks[] = $wanted;
            }
        }
        foreach ($unmatched as $check) {
            $diff->droppedChecks[] = $check;
        }

        return $diff;
    }

    /** True when $from must be altered to become $to. */
    public function columnChanged(Column $from, Column $to, ?Table $fromTable = null): bool
    {
        $custom = TypeRegistry::get($to->type); // a custom type normalizes both sides of its column
        $current = $this->columnSignature($from, $custom);
        $wanted = $this->columnSignature($to, $custom);
        // charset / collation are compared only when the wanted column sets them (unset: the table default)
        if ($to->charset !== null && in_array($this->platform, ['mysql', 'mariadb'], true)) {
            $current['charset'] = strtolower((string) ($from->charset ?? $fromTable?->defaultCharset));
            $wanted['charset'] = strtolower($to->charset);
        }
        if ($to->collation !== null) {
            $current['collation'] = strtolower((string) ($from->collation ?? $fromTable?->defaultCollation));
            $wanted['collation'] = strtolower($to->collation);
        }

        return $current !== $wanted;
    }

    /**
     * What is compared for a column: canonical type, unsigned, nullability, default, auto-increment, comment,
     * enum values of native enums (charset / collation: see columnChanged()).
     *
     * @return array<string, mixed>
     */
    public function columnSignature(Column $column, ?ColumnType $custom = null): array
    {
        $column = $this->grammar->resolve($column);
        [$type, $unsigned] = $this->canonicalType($column);
        if ($custom !== null) {
            $type = $custom->normalizeDatabaseType($type, $this->platform);
        }
        $signature = [
            'type' => $type,
            'unsigned' => in_array($this->platform, ['mysql', 'mariadb'], true) && $unsigned,
            'nullable' => $column->nullable && ! $column->primary,
            'default' => Normalizer::defaultValue($column, $type),
            'autoIncrement' => $column->autoIncrement,
            'comment' => $this->platform === 'sqlite' ? null : (($column->comment ?? '') === '' ? null : $column->comment),
            'charset' => null,
            'collation' => null,
            'generated' => $column->generatedType !== null && $column->asExpression !== null
                ? strtoupper($column->generatedType) . ':' . Normalizer::generationExpression($column->asExpression)
                : null,
        ];
        if ($signature['generated'] !== null) {
            $signature['nullable'] = true; // generated columns are declared without NOT NULL
            $signature['default'] = null;
        }
        if (($this->platform === 'pgsql' && $column->enumName !== null)
            || ($this->platform === 'sqlite' && in_array($column->type, ['enum', 'simple-enum'], true))) {
            $signature['enum'] = array_map('strval', $column->values); // native enum type / SQLite CHECK
        }

        return $signature;
    }

    /** @return array{0: string, 1: bool} canonical type and unsigned flag */
    public function canonicalType(Column $column): array
    {
        $sql = $column->rawType ?? $this->grammar->typeSql($column);

        return Normalizer::type($this->platform, $sql);
    }

    private function sameForeignKey(ForeignKey $actual, ForeignKey $wanted): bool
    {
        return Normalizer::sameColumns($actual->columns, $wanted->columns)
            && strcasecmp($actual->referencedTable, $wanted->referencedTable) === 0
            && Normalizer::sameColumns($actual->referencedColumns, $wanted->referencedColumns)
            && Normalizer::action($this->platform, $actual->onDelete) === Normalizer::action($this->platform, $wanted->onDelete)
            && Normalizer::action($this->platform, $actual->onUpdate) === Normalizer::action($this->platform, $wanted->onUpdate)
            && (! $wanted->explicitName || ($actual->name !== null && strcasecmp($actual->name, $wanted->name ?? '') === 0));
    }

    private function sameIndex(Index $actual, Index $wanted): bool
    {
        if ($actual->unique !== $wanted->unique || ! Normalizer::sameColumns($actual->columns, $wanted->columns) || $actual->columns === []) {
            return false;
        }
        if (! in_array($this->platform, ['mysql', 'mariadb'], true)
            && Normalizer::expression((string) $actual->where) !== Normalizer::expression((string) $wanted->where)) {
            return false;
        }
        if (! $wanted->explicitName) {
            return true;
        }

        return $actual->name !== null && strcasecmp($actual->name, (string) $wanted->name) === 0;
    }

    /**
     * Remove and return the first element of $list matching $predicate.
     *
     * @template T
     * @param list<T> $list
     * @return T|null
     */
    private function takeMatch(array &$list, \Closure $predicate): mixed
    {
        foreach ($list as $i => $item) {
            if ($predicate($item)) {
                unset($list[$i]);
                $list = array_values($list);

                return $item;
            }
        }

        return null;
    }

    /** @param array<string, Table> $tables */
    private function find(array $tables, string $name): ?Table
    {
        if (isset($tables[$name])) {
            return $tables[$name];
        }
        foreach ($tables as $key => $table) {
            if (strcasecmp((string) $key, $name) === 0) {
                return $table;
            }
        }

        return null;
    }
}
