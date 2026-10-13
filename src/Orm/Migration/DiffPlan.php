<?php

declare(strict_types=1);

namespace Nesto\Orm\Migration;

use Nesto\Orm\Schema\Comparison\SchemaDiff;
use Nesto\Orm\Schema\Table;
use Nesto\Orm\Schema\View;

/**
 * Turns a SchemaDiff into ordered steps over the Schema / Blueprint API, so synchronization, `schema:log`
 * and generated migrations share one path:
 *
 *   1. drop the foreign keys that change or go away (other tables' columns / keys may depend on them)
 *   2. create tables, in dependency order
 *   3. alter tables: drop checks / indexes / primary keys / columns, add and change columns, add keys,
 *      indexes and checks
 *   4. add the new foreign keys (every referenced table and column now exists)
 *   5. drop tables (reverse order)
 *
 * Views that change or go away are dropped before everything else; new and changed views are created last.
 *
 * SQLite alters a table in one step (one table rebuild when needed) and declares foreign keys inline.
 *
 *   (new DiffPlan($diff))->apply($schema);   // run it
 *   (new DiffPlan($diff))->toSql($schema);   // the statements it would run, nothing executed
 */
final class DiffPlan
{
    public function __construct(public readonly SchemaDiff $diff) {}

    /**
     * @return list<array{type: 'create'|'alter'|'drop'|'dropView'|'createView', table: string, blueprint: ?Blueprint, schema: ?Table, view?: View}>
     */
    public function steps(): array
    {
        $sqlite = $this->diff->platform === 'sqlite';
        $dropForeign = $alter = $addForeign = [];
        $creates = [];
        $createdSoFar = [];
        $createdNames = array_map(fn (Table $t) => strtolower($t->name), $this->diff->created);

        foreach ($this->diff->created as $table) {
            $blueprint = Blueprint::fromTable($table);
            if (! $sqlite) {
                $inline = [];
                $deferred = new Blueprint($table->name);
                foreach ($blueprint->foreignKeys as $fk) {
                    $target = strtolower((string) $fk->referencedTable);
                    if ($target !== strtolower($table->name) && in_array($target, $createdNames, true) && ! isset($createdSoFar[$target])) {
                        $deferred->foreignKeys[] = $fk; // references a table created later in this diff
                    } else {
                        $inline[] = $fk;
                    }
                }
                $blueprint->foreignKeys = $inline;
                if ($deferred->foreignKeys !== []) {
                    $addForeign[] = ['type' => 'alter', 'table' => $table->name, 'blueprint' => $deferred, 'schema' => null];
                }
            }
            $createdSoFar[strtolower($table->name)] = true;
            $creates[] = ['type' => 'create', 'table' => $table->name, 'blueprint' => $blueprint, 'schema' => $table];
        }

        foreach ($this->diff->altered as $diff) {
            $first = new Blueprint($diff->table);
            $main = $sqlite ? $first : new Blueprint($diff->table);
            $last = $sqlite ? $first : new Blueprint($diff->table);
            foreach ($diff->droppedForeignKeys as $fk) {
                $first->dropForeign($fk->name !== null && ! $sqlite ? $fk->name : $fk->columns);
            }
            foreach ($diff->droppedChecks as $check) {
                $main->dropCheck($check->name ?? $check->expression);
            }
            foreach ($diff->droppedIndexes as $index) {
                $target = $index->name !== null && ! ($sqlite && $index->constraint) ? $index->name : $index->columns;
                $index->unique ? $main->dropUnique($target) : $main->dropIndex($target);
            }
            if ($diff->primaryKey !== null && $diff->primaryKey[0] !== []) {
                $main->dropPrimary();
            }
            $added = [];
            foreach ($diff->addedColumns as $column) {
                $copy = ColumnDefinition::of($column);
                $copy->primary = false;
                $added[strtolower($column->name)] = $main->add($copy);
            }
            foreach ($diff->changedColumns as [$current, $wanted]) {
                $copy = ColumnDefinition::of($wanted); // complete: nothing is inherited from the live column
                $copy->primary = false;
                $copy->previous = $current;
                $main->add($copy->change());
            }
            if ($diff->droppedColumns !== []) {
                $main->dropColumn(array_map(fn ($c) => $c->name, $diff->droppedColumns));
            }
            if ($diff->primaryKey !== null && $diff->primaryKey[1] !== []) {
                $main->primary($diff->primaryKey[1]);
                $main->primaryKeyName = $diff->to->primaryKeyName;
            }
            foreach ($diff->addedIndexes as $index) {
                $column = count($index->columns) === 1 ? ($added[strtolower($index->columns[0])] ?? null) : null;
                if ($column !== null && $index->unique && $index->constraint && ! $index->explicitName && $index->where === null) {
                    $column->unique = true; // a new column's own UNIQUE
                    continue;
                }
                $entry = ['columns' => $index->columns, 'unique' => $index->unique, 'name' => $index->explicitName ? $index->name : null, 'where' => $index->where]
                    + array_filter(['sorts' => $index->sorts, 'lengths' => $index->lengths, 'using' => $index->using], fn ($v) => $v !== [] && $v !== null);
                $main->indexes[] = $entry;
            }
            foreach ($diff->addedChecks as $check) {
                $main->check($check->expression, $check->name);
            }
            foreach ($diff->addedForeignKeys as $fk) {
                $last->foreign(count($fk->columns) === 1 ? $fk->columns[0] : $fk->columns, $fk->explicitName ? $fk->name : null)
                    ->references(count($fk->referencedColumns) === 1 ? $fk->referencedColumns[0] : $fk->referencedColumns)
                    ->on($fk->referencedTable)
                    ->onDelete($fk->onDelete)
                    ->onUpdate($fk->onUpdate);
            }
            $step = fn (Blueprint $blueprint) => ['type' => 'alter', 'table' => $diff->table, 'blueprint' => $blueprint, 'schema' => $diff->from];
            if ($sqlite) {
                $alter[] = $step($first);
                continue;
            }
            if (self::hasContent($first)) {
                $dropForeign[] = $step($first);
            }
            if (self::hasContent($main)) {
                $alter[] = $step($main);
            }
            if (self::hasContent($last)) {
                $addForeign[] = $step($last);
            }
        }

        $drops = array_map(fn (Table $t) => ['type' => 'drop', 'table' => $t->name, 'blueprint' => null, 'schema' => $t], $this->diff->dropped);
        if (! $sqlite) {
            // tables referencing each other (A → B → A) cannot all be dropped first: release the foreign keys
            // that point at a table dropped before their own
            $order = [];
            foreach ($this->diff->dropped as $i => $table) {
                $order[strtolower($table->name)] = $i;
            }
            foreach ($this->diff->dropped as $i => $table) {
                $release = new Blueprint($table->name);
                foreach ($table->foreignKeys as $fk) {
                    if (($order[strtolower($fk->referencedTable)] ?? PHP_INT_MAX) < $i) {
                        $release->dropForeign($fk->explicitName && $fk->name !== null ? $fk->name : $fk->columns);
                    }
                }
                if (self::hasContent($release)) {
                    $dropForeign[] = ['type' => 'alter', 'table' => $table->name, 'blueprint' => $release, 'schema' => $table];
                }
            }
        }
        $dropViews = array_map(fn (View $v) => ['type' => 'dropView', 'table' => $v->name, 'blueprint' => null, 'schema' => null, 'view' => $v], $this->diff->droppedViews);
        $createViews = array_map(fn (View $v) => ['type' => 'createView', 'table' => $v->name, 'blueprint' => null, 'schema' => null, 'view' => $v], $this->diff->createdViews);

        return [...$dropViews, ...$dropForeign, ...$creates, ...$alter, ...$addForeign, ...$drops, ...$createViews];
    }

    public function apply(Schema $schema): void
    {
        $logger = $schema->isPretending() ? null : $schema->getConnection()->getOrmLogger();
        foreach ($this->steps() as $step) {
            $logger?->logSchemaBuild(match ($step['type']) {
                'create' => 'creating a new table: ' . $step['table'],
                'alter' => 'altering table: ' . $step['table'],
                'drop' => 'dropping table: ' . $step['table'],
                'dropView' => 'dropping an old view: ' . $step['table'],
                'createView' => 'creating a new view: ' . $step['table'],
                default => $step['type'] . ': ' . $step['table'],
            });
            match ($step['type']) {
                'create' => $schema->createFromBlueprint($step['blueprint']),
                'alter' => $schema->alterFromBlueprint($step['blueprint']),
                'drop' => $schema->dropTable($step['table']),
                'dropView' => $schema->dropView($step['table'], $step['view']->materialized),
                'createView' => $schema->createView($step['table'], (string) $step['view']->expression, $step['view']->materialized),
            };
        }
    }

    /** @return list<string> the statements apply() would run (nothing is executed) */
    public function toSql(Schema $schema): array
    {
        return $schema->pretend(fn () => $this->apply($schema));
    }

    private static function hasContent(Blueprint $blueprint): bool
    {
        return $blueprint->columns !== [] || $blueprint->commands !== [] || $blueprint->indexes !== [] || $blueprint->foreignKeys !== []
            || $blueprint->checks !== [] || $blueprint->primaryKeys !== [];
    }
}
