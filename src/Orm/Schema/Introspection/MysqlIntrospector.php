<?php

declare(strict_types=1);

namespace Nesto\Orm\Schema\Introspection;

use Nesto\Orm\Schema\Check;
use Nesto\Orm\Schema\ForeignKey;
use Nesto\Orm\Schema\Index;
use Nesto\Orm\Schema\Normalizer;
use Nesto\Orm\Schema\Table;

/**
 * MySQL and MariaDB through information_schema. Differences handled here: MySQL reports literal defaults
 * unquoted and expression defaults as DEFAULT_GENERATED (`_utf8mb4\'[]\'`), MariaDB quotes literals and
 * reports `NULL`; MariaDB's JSON is LONGTEXT plus an automatic json_valid() CHECK; indexes MySQL creates
 * for foreign keys are marked implicit.
 */
final class MysqlIntrospector extends Introspector
{
    public function tableNames(): array
    {
        return array_map(
            fn (array $r) => (string) $r['name'],
            $this->connection->fetchAll("SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name"),
        );
    }

    public function viewNames(): array
    {
        return array_fill_keys(array_map(
            fn (array $r) => (string) $r['name'],
            $this->connection->fetchAll('SELECT table_name AS name FROM information_schema.views WHERE table_schema = DATABASE() ORDER BY table_name'),
        ), false);
    }

    public function table(string $name): ?Table
    {
        $rows = $this->connection->fetchAll(
            'SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_default AS dflt, extra AS extra,'
            . ' column_comment AS comment, character_set_name AS charset, collation_name AS collation, generation_expression AS generation'
            . ' FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t ORDER BY ordinal_position',
            ['t' => $name],
        );
        if ($rows === []) {
            return null;
        }
        $mariadb = $this->connection->platform() === 'mariadb';
        $table = new Table($name);
        $defaults = $this->connection->fetchOne(
            'SELECT t.table_collation AS collation, c.character_set_name AS charset FROM information_schema.tables t'
            . ' LEFT JOIN information_schema.collation_character_set_applicability c ON c.collation_name = t.table_collation'
            . ' WHERE t.table_schema = DATABASE() AND t.table_name = :t',
            ['t' => $name],
        );
        $table->defaultCollation = isset($defaults['collation']) ? (string) $defaults['collation'] : null;
        $table->defaultCharset = isset($defaults['charset']) ? (string) $defaults['charset'] : null;

        $checks = $this->connection->fetchAll(
            'SELECT tc.constraint_name AS name, cc.check_clause AS clause FROM information_schema.table_constraints tc'
            . ' JOIN information_schema.check_constraints cc ON cc.constraint_schema = tc.constraint_schema AND cc.constraint_name = tc.constraint_name'
            . " WHERE tc.table_schema = DATABASE() AND tc.table_name = :t AND tc.constraint_type = 'CHECK'"
            . ($mariadb ? ' AND cc.table_name = :t2' : ''),
            $mariadb ? ['t' => $name, 't2' => $name] : ['t' => $name],
        );
        $jsonColumns = [];
        foreach ($checks as $check) {
            // MariaDB's JSON type: LONGTEXT + CHECK (json_valid(`col`)) named after the column
            if ($mariadb && preg_match('/^json_valid\(`?([^`)]+)`?\)$/i', trim((string) $check['clause']), $m) === 1 && $m[1] === $check['name']) {
                $jsonColumns[strtolower($m[1])] = true;
                continue;
            }
            $table->checks[] = new Check((string) $check['clause'], (string) $check['name']);
        }

        foreach ($rows as $row) {
            $rawType = (string) $row['type'];
            $extra = strtolower((string) $row['extra']);
            $autoIncrement = str_contains($extra, 'auto_increment');
            $generated = str_contains($extra, 'stored generated') || str_contains($extra, 'persistent generated') ? 'STORED'
                : (str_contains($extra, 'virtual generated') ? 'VIRTUAL' : null);
            $column = $this->column(
                (string) $row['name'],
                $rawType,
                strtoupper((string) $row['nullable']) === 'YES',
                $generated !== null ? null : $this->parseDefault($row['dflt'], $extra, $mariadb, $rawType),
                $autoIncrement,
            );
            if ($generated !== null) {
                $column->generatedType = $generated;
                $column->asExpression = (string) $row['generation'];
            }
            if (isset($jsonColumns[strtolower($column->name)])) {
                $column = $this->column($column->name, 'json', $column->nullable, $column->hasDefault ? $column->default : null, $autoIncrement);
                $column->rawType = $rawType;
            }
            if ($column->type === 'enum') {
                $column->values = self::enumValues($rawType);
            }
            $column->comment = (string) $row['comment'] !== '' ? (string) $row['comment'] : null;
            // only report what differs from the table defaults (what an entity would have to say)
            $column->charset = $row['charset'] !== null && $row['charset'] !== $table->defaultCharset ? (string) $row['charset'] : null;
            $column->collation = $row['collation'] !== null && $row['collation'] !== $table->defaultCollation ? (string) $row['collation'] : null;
            $table->columns[$column->name] = $column;
        }

        $fkRows = $this->connection->fetchAll(
            'SELECT kcu.constraint_name AS name, kcu.column_name AS col, kcu.referenced_table_name AS ref_table, kcu.referenced_column_name AS ref_col,'
            . ' rc.update_rule AS on_update, rc.delete_rule AS on_delete'
            . ' FROM information_schema.key_column_usage kcu'
            . ' JOIN information_schema.referential_constraints rc ON rc.constraint_schema = kcu.constraint_schema AND rc.constraint_name = kcu.constraint_name AND rc.table_name = kcu.table_name'
            . ' WHERE kcu.table_schema = DATABASE() AND kcu.table_name = :t AND kcu.referenced_table_name IS NOT NULL'
            . ' ORDER BY kcu.constraint_name, kcu.ordinal_position',
            ['t' => $name],
        );
        $fks = [];
        foreach ($fkRows as $row) {
            $fkName = (string) $row['name'];
            $fks[$fkName] ??= ['columns' => [], 'refTable' => (string) $row['ref_table'], 'refColumns' => [], 'onUpdate' => (string) $row['on_update'], 'onDelete' => (string) $row['on_delete']];
            $fks[$fkName]['columns'][] = (string) $row['col'];
            $fks[$fkName]['refColumns'][] = (string) $row['ref_col'];
        }
        foreach ($fks as $fkName => $fk) {
            $table->foreignKeys[] = new ForeignKey(
                $fk['columns'],
                $fk['refTable'],
                $fk['refColumns'],
                self::action($fk['onDelete']),
                self::action($fk['onUpdate']),
                $fkName,
                preg_match('/^' . preg_quote($name, '/') . '_ibfk_\d+$/', $fkName) !== 1,
            );
        }

        $indexRows = $this->connection->fetchAll(
            'SELECT index_name AS name, non_unique AS non_unique, column_name AS col, index_type AS type, seq_in_index AS seq'
            . ' FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :t ORDER BY index_name, seq_in_index',
            ['t' => $name],
        );
        $indexes = [];
        foreach ($indexRows as $row) {
            $indexName = (string) $row['name'];
            $indexes[$indexName] ??= ['unique' => (int) $row['non_unique'] === 0, 'columns' => [], 'type' => strtoupper((string) $row['type']), 'expression' => false];
            if ($row['col'] === null) {
                $indexes[$indexName]['expression'] = true;
            } else {
                $indexes[$indexName]['columns'][] = (string) $row['col'];
            }
        }
        foreach ($indexes as $indexName => $index) {
            if ($indexName === 'PRIMARY') {
                $table->primaryKey = $index['columns'];
                continue;
            }
            $implicit = $index['expression'] || $index['type'] !== 'BTREE';
            if (! $index['unique'] && ! $implicit) {
                foreach ($table->foreignKeys as $fk) {
                    // InnoDB's own index for a foreign key: named after the constraint, or the first column
                    if (Normalizer::sameColumns($fk->columns, $index['columns']) && ($indexName === $fk->name || $indexName === $fk->columns[0])) {
                        $implicit = true;
                    }
                }
            }
            // a column-level UNIQUE is named after its column: not a name an entity chose
            $explicit = ! ($index['unique'] && count($index['columns']) === 1 && $indexName === $index['columns'][0]);
            $table->indexes[] = new Index($index['columns'], $index['unique'], $indexName, $explicit, null, $index['unique'], $implicit);
        }
        foreach ($table->primaryKey as $columnName) {
            if (isset($table->columns[$columnName])) {
                $table->columns[$columnName]->primary = true;
            }
        }

        return $table;
    }

    private function parseDefault(mixed $default, string $extra, bool $mariadb, string $rawType): mixed
    {
        if ($default === null) {
            return null;
        }
        $default = (string) $default;
        if ($mariadb || str_contains($extra, 'default_generated')) {
            return Normalizer::parseDefault($default);
        }
        // MySQL: literal defaults come unquoted
        $canonical = Normalizer::type('mysql', $rawType)[0];
        if (Normalizer::isBoolean($canonical) && ($default === '0' || $default === '1')) {
            return $default === '1';
        }
        if (Normalizer::isInteger($canonical) && preg_match('/^-?\d+$/', $default) === 1) {
            return (int) $default;
        }

        return $default;
    }

    private static function action(string $rule): ?string
    {
        $rule = strtoupper($rule);

        return $rule === '' || $rule === 'NO ACTION' || $rule === 'RESTRICT' ? ($rule === 'RESTRICT' ? 'RESTRICT' : null) : $rule;
    }
}
