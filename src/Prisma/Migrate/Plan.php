<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

use Nesto\Orm\Connection;
use Nesto\Orm\Migration\DiffPlan;
use Nesto\Orm\Migration\Grammar;
use Nesto\Orm\Migration\Schema;
use Nesto\Orm\Schema\Comparison\Comparator;
use Nesto\Orm\Schema\Comparison\SchemaDiff;
use Nesto\Orm\Schema\Index;

/**
 * What a migration does (Differ::plan()): statements run before the table changes (PostgreSQL enum types),
 * the shared engine's SchemaDiff (run through DiffPlan), statements run after (DROP TYPE).
 *
 *   $plan->statements($schema);              // the SQL, nothing executed (migration.sql, db push --print)
 *   $plan->apply($schema);                   // run it
 *   $plan->warnings($connection, true);      // data loss / unexecutable steps for the data in $connection
 */
final class Plan
{
    /**
     * @param list<string>             $before
     * @param list<string>             $after
     * @param array<string, list<string>> $enumWarnings PostgreSQL enum => values removed from it
     */
    public function __construct(
        public readonly SchemaDiff $diff,
        public readonly array $before,
        public readonly array $after,
        public readonly array $enumWarnings,
        public readonly DatabaseSchema $from,
        public readonly DatabaseSchema $to,
    ) {}

    public function isEmpty(): bool
    {
        return $this->before === [] && $this->after === [] && $this->diff->isEmpty();
    }

    /** @return list<string> */
    public function statements(Schema $schema): array
    {
        // every enum type of either side exists by then (created by $before, or already there)
        $created = array_fill_keys([...array_keys($this->from->enums), ...array_keys($this->to->enums)], true);
        $tableStatements = array_values(array_filter(
            (new DiffPlan($this->diff))->toSql($schema),
            // the engine's idempotent CREATE TYPE blocks: the type is created above or exists already
            fn (string $sql) => ! (preg_match('/^DO \$\$ BEGIN CREATE TYPE "([^"]+)"/', $sql, $m) === 1 && isset($created[$m[1]])),
        ));
        $tableStatements = array_map(
            fn (string $sql) => preg_match('/^DO \$\$ BEGIN (CREATE TYPE .*?\)); EXCEPTION WHEN duplicate_object THEN NULL; END \$\$$/s', $sql, $m) === 1 ? $m[1] : $sql,
            $tableStatements,
        );

        return [...$this->before, ...$tableStatements, ...$this->after];
    }

    public function apply(Schema $schema): void
    {
        foreach ($this->before as $sql) {
            $schema->statement($sql);
        }
        (new DiffPlan($this->diff))->apply($schema);
        foreach ($this->after as $sql) {
            $schema->statement($sql);
        }
    }

    /**
     * Prisma's warnings for this plan. With $onlyWithData (db push, migrate dev's confirmation) a step only
     * warns when the rows in $connection make it lossy; without (the header of migration.sql) every
     * potentially destructive step is listed.
     *
     * @return list<MigrationWarning>
     */
    public function warnings(Connection $connection, bool $onlyWithData): array
    {
        $q = fn (string $name) => $connection->quote($name);
        $count = function (string $sql) use ($connection): int {
            try {
                return (int) $connection->fetchColumn($sql);
            } catch (\Throwable) {
                return 0;
            }
        };
        $comparator = new Comparator(new Grammar($connection));
        $warnings = [];
        foreach ($this->diff->dropped as $table) {
            $rows = $count('SELECT COUNT(*) FROM ' . $q($table->name));
            if (! $onlyWithData) {
                $warnings[] = new MigrationWarning(sprintf('You are about to drop the `%s` table. If the table is not empty, all the data it contains will be lost.', $table->name));
            } elseif ($rows > 0) {
                $warnings[] = new MigrationWarning(sprintf('You are about to drop the `%s` table, which is not empty (%d rows).', $table->name, $rows));
            }
        }
        foreach ($this->diff->altered as $tableDiff) {
            $table = $q($tableDiff->table);
            $rows = $count("SELECT COUNT(*) FROM $table");
            foreach ($tableDiff->droppedColumns as $column) {
                $values = $count("SELECT COUNT(*) FROM $table WHERE " . $q($column->name) . ' IS NOT NULL');
                if (! $onlyWithData) {
                    $warnings[] = new MigrationWarning(sprintf('You are about to drop the column `%s` on the `%s` table. All the data in the column will be lost.', $column->name, $tableDiff->table));
                } elseif ($values > 0) {
                    $warnings[] = new MigrationWarning(sprintf('You are about to drop the column `%s` on the `%s` table, which still contains %d non-null values.', $column->name, $tableDiff->table, $values));
                }
            }
            foreach ($tableDiff->changedColumns as [$current, $wanted]) {
                $fromType = $comparator->canonicalType($current)[0];
                $toType = $comparator->canonicalType($wanted)[0];
                if ($fromType !== $toType && $current->type === 'enum' && $wanted->type === 'enum' && $current->enumName === null) {
                    // MySQL / MariaDB ENUM(...) columns: only removed values can fail
                    $removed = array_values(array_diff(array_map('strval', $current->values), array_map('strval', $wanted->values)));
                    if ($removed !== []) {
                        $used = $onlyWithData ? $count("SELECT COUNT(*) FROM $table WHERE " . $q($current->name) . ' IN (' . implode(', ', array_map(fn (string $v) => "'" . str_replace("'", "''", $v) . "'", $removed)) . ')') : 0;
                        if (! $onlyWithData || $used > 0) {
                            $warnings[] = new MigrationWarning(sprintf('The values [%s] on the enum `%s_%s` will be removed. If these variants are still used in the database, this will fail.', implode(',', $removed), $tableDiff->table, $current->name));
                        }
                    }
                    $fromType = $toType;
                }
                if ($fromType !== $toType) {
                    $fromType = (string) ($current->rawType ?? $fromType);
                    $toType = (new Grammar($connection))->typeSql($wanted);
                    $values = $count("SELECT COUNT(*) FROM $table WHERE " . $q($current->name) . ' IS NOT NULL');
                    if (! $onlyWithData) {
                        $warnings[] = new MigrationWarning(sprintf('You are about to alter the column `%s` on the `%s` table. The data in that column will be cast from `%s` to `%s`. If the values cannot be cast, this will fail.', $current->name, $tableDiff->table, $fromType, $toType));
                    } elseif ($values > 0) {
                        $warnings[] = new MigrationWarning(sprintf('You are about to alter the column `%s` on the `%s` table, which contains %d non-null values. The data in that column will be cast from `%s` to `%s`.', $current->name, $tableDiff->table, $values, $fromType, $toType));
                    }
                }
                if ($current->nullable && ! $wanted->nullable && ! $wanted->primary) {
                    $nulls = $count("SELECT COUNT(*) FROM $table WHERE " . $q($current->name) . ' IS NULL');
                    if (! $onlyWithData) {
                        $warnings[] = new MigrationWarning(sprintf('Made the column `%s` on table `%s` required. If there are existing NULL values, this will fail.', $current->name, $tableDiff->table));
                    } elseif ($nulls > 0) {
                        $warnings[] = new MigrationWarning(sprintf('Made the column `%s` on table `%s` required, but there are %d existing NULL values.', $current->name, $tableDiff->table, $nulls), true);
                    }
                }
            }
            foreach ($tableDiff->addedColumns as $column) {
                $required = ! $column->nullable && ! ($column->hasDefault && $column->default !== null) && ! $column->autoIncrement && $column->generatedType === null;
                if (! $required) {
                    continue;
                }
                if (! $onlyWithData) {
                    $warnings[] = new MigrationWarning(sprintf('Added the required column `%s` to the `%s` table without a default value. This is not possible if the table is not empty.', $column->name, $tableDiff->table));
                } elseif ($rows > 0) {
                    $warnings[] = new MigrationWarning(sprintf('Added the required column `%s` to the `%s` table without a default value. There are %d rows in this table, it is not possible to execute this step.', $column->name, $tableDiff->table, $rows), true);
                }
            }
            foreach ($tableDiff->addedIndexes as $index) {
                /** @var Index $index */
                if ($index->unique && (! $onlyWithData || $rows > 0)) {
                    $warnings[] = new MigrationWarning(sprintf('A unique constraint covering the columns `[%s]` on the table `%s` will be added. If there are existing duplicate values, this will fail.', implode(',', $index->columns), $tableDiff->table));
                }
            }
        }
        foreach ($this->enumWarnings as $enum => $removed) {
            $used = 0;
            if ($onlyWithData) {
                $list = implode(', ', array_map(fn (string $v) => "'" . str_replace("'", "''", $v) . "'", $removed));
                foreach ($this->from->tables as $table) {
                    foreach ($table->columns as $column) {
                        if ($column->enumName === $enum) {
                            $used += $count('SELECT COUNT(*) FROM ' . $q($table->name) . ' WHERE ' . ($column->array
                                ? 'ARRAY[' . $list . ']::text[] && ' . $q($column->name) . '::text[]'
                                : $q($column->name) . '::text IN (' . $list . ')'));
                        }
                    }
                }
            }
            if (! $onlyWithData || $used > 0) {
                $warnings[] = new MigrationWarning(sprintf('The values [%s] on the enum `%s` will be removed. If these variants are still used in the database, this will fail.', implode(',', $removed), $enum));
            }
        }

        return $warnings;
    }
}
