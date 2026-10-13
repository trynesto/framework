<?php

declare(strict_types=1);

namespace Nesto\Orm\Schema\Introspection;

use Nesto\Orm\Schema\Check;
use Nesto\Orm\Schema\ForeignKey;
use Nesto\Orm\Schema\Index;
use Nesto\Orm\Schema\Normalizer;
use Nesto\Orm\Schema\Table;

/**
 * PostgreSQL through pg_catalog (current schema): format_type() for exact types, pg_get_expr() for
 * defaults and partial-index predicates, pg_constraint for keys / foreign keys / uniques / checks.
 * SERIAL and IDENTITY columns are auto-increment; native enums report their type name and labels.
 */
final class PostgresIntrospector extends Introspector
{
    private const ACTIONS = ['a' => null, 'r' => 'RESTRICT', 'c' => 'CASCADE', 'n' => 'SET NULL', 'd' => 'SET DEFAULT'];

    public function tableNames(): array
    {
        return array_map(
            fn (array $r) => (string) $r['name'],
            $this->connection->fetchAll("SELECT table_name AS name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' ORDER BY table_name"),
        );
    }

    public function viewNames(): array
    {
        $views = [];
        foreach ($this->connection->fetchAll('SELECT viewname AS name FROM pg_views WHERE schemaname = current_schema() ORDER BY viewname') as $row) {
            $views[(string) $row['name']] = false;
        }
        foreach ($this->connection->fetchAll('SELECT matviewname AS name FROM pg_matviews WHERE schemaname = current_schema() ORDER BY matviewname') as $row) {
            $views[(string) $row['name']] = true;
        }

        return $views;
    }

    public function table(string $name): ?Table
    {
        $rows = $this->connection->fetchAll(
            'SELECT a.attname AS name, format_type(a.atttypid, a.atttypmod) AS type, a.attnotnull AS notnull,'
            . ' pg_get_expr(d.adbin, d.adrelid) AS dflt, col_description(c.oid, a.attnum) AS comment, a.attidentity AS identity, a.attgenerated AS generated,'
            . ' t.typtype AS typtype, t.typname AS typname,'
            . ' CASE WHEN a.attcollation <> t.typcollation THEN co.collname END AS collation'
            . ' FROM pg_attribute a'
            . ' JOIN pg_class c ON c.oid = a.attrelid'
            . ' JOIN pg_namespace n ON n.oid = c.relnamespace'
            . ' JOIN pg_type t ON t.oid = a.atttypid'
            . ' LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum'
            . ' LEFT JOIN pg_collation co ON co.oid = a.attcollation'
            . " WHERE n.nspname = current_schema() AND c.relname = :t AND c.relkind IN ('r', 'p') AND a.attnum > 0 AND NOT a.attisdropped"
            . ' ORDER BY a.attnum',
            ['t' => $name],
        );
        if ($rows === []) {
            return null;
        }
        $table = new Table($name);
        foreach ($rows as $row) {
            $default = $row['dflt'] !== null ? (string) $row['dflt'] : null;
            $generated = match ((string) $row['generated']) {
                's' => 'STORED',
                'v' => 'VIRTUAL',
                default => null,
            };
            if ($generated !== null) {
                $default = null; // pg_attrdef holds the generation expression
            }
            $autoIncrement = ($default !== null && str_starts_with($default, 'nextval(')) || in_array((string) $row['identity'], ['a', 'd'], true);
            $rawType = (string) $row['type'];
            $column = $this->column(
                (string) $row['name'],
                $row['typtype'] === 'e' ? 'enum' : $rawType,
                ! self::bool($row['notnull']),
                $autoIncrement ? null : Normalizer::parseDefault($default),
                $autoIncrement,
            );
            $column->rawType = $rawType;
            if ($generated !== null) {
                $column->generatedType = $generated;
                $column->asExpression = (string) $row['dflt'];
            }
            if (in_array((string) $row['identity'], ['a', 'd'], true)) {
                $column->identity = $row['identity'] === 'a' ? 'ALWAYS' : 'BY DEFAULT';
            }
            if ($row['typtype'] === 'e') {
                $column->enumName = (string) $row['typname'];
                $column->values = $this->enumLabels((string) $row['typname']);
            }
            $column->comment = $row['comment'] !== null && $row['comment'] !== '' ? (string) $row['comment'] : null;
            $column->collation = $row['collation'] !== null ? (string) $row['collation'] : null;
            $table->columns[$column->name] = $column;
        }

        $constraints = [];
        foreach ($this->connection->fetchAll(
            'SELECT con.conname AS name, con.contype AS type, k.ord AS ord, a.attname AS col, fc.relname AS ref_table, fa.attname AS ref_col,'
            . ' con.confupdtype AS on_update, con.confdeltype AS on_delete'
            . ' FROM pg_constraint con'
            . ' JOIN pg_class c ON c.oid = con.conrelid'
            . ' JOIN pg_namespace n ON n.oid = c.relnamespace'
            . ' CROSS JOIN LATERAL unnest(con.conkey) WITH ORDINALITY AS k(attnum, ord)'
            . ' JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum'
            . ' LEFT JOIN pg_class fc ON fc.oid = con.confrelid'
            . ' LEFT JOIN pg_attribute fa ON fa.attrelid = con.confrelid AND fa.attnum = con.confkey[k.ord]'
            . " WHERE n.nspname = current_schema() AND c.relname = :t AND con.contype IN ('p', 'u', 'f')"
            . ' ORDER BY con.conname, k.ord',
            ['t' => $name],
        ) as $row) {
            $key = (string) $row['name'];
            $constraints[$key] ??= ['type' => (string) $row['type'], 'columns' => [], 'refTable' => $row['ref_table'], 'refColumns' => [], 'onUpdate' => (string) $row['on_update'], 'onDelete' => (string) $row['on_delete']];
            $constraints[$key]['columns'][] = (string) $row['col'];
            if ($row['ref_col'] !== null) {
                $constraints[$key]['refColumns'][] = (string) $row['ref_col'];
            }
        }
        $quoted = preg_quote($name, '/');
        foreach ($constraints as $constraintName => $constraint) {
            match ($constraint['type']) {
                'p' => [$table->primaryKey, $table->primaryKeyName] = [$constraint['columns'], $constraintName],
                'u' => $table->indexes[] = new Index(
                    $constraint['columns'],
                    true,
                    $constraintName,
                    preg_match('/^' . $quoted . '_.+_key\d*$/', $constraintName) !== 1,
                    null,
                    true,
                ),
                default => $table->foreignKeys[] = new ForeignKey(
                    $constraint['columns'],
                    (string) $constraint['refTable'],
                    $constraint['refColumns'],
                    self::ACTIONS[$constraint['onDelete']] ?? null,
                    self::ACTIONS[$constraint['onUpdate']] ?? null,
                    $constraintName,
                    preg_match('/^' . $quoted . '_.+_fkey\d*$/', $constraintName) !== 1,
                ),
            };
        }
        foreach ($table->primaryKey as $columnName) {
            if (isset($table->columns[$columnName])) {
                $table->columns[$columnName]->primary = true;
            }
        }

        $indexes = [];
        foreach ($this->connection->fetchAll(
            'SELECT i.relname AS name, ix.indisunique AS is_unique, pg_get_expr(ix.indpred, ix.indrelid) AS predicate, k.ord AS ord, a.attname AS col'
            . ' FROM pg_index ix'
            . ' JOIN pg_class i ON i.oid = ix.indexrelid'
            . ' JOIN pg_class c ON c.oid = ix.indrelid'
            . ' JOIN pg_namespace n ON n.oid = c.relnamespace'
            . ' CROSS JOIN LATERAL unnest(ix.indkey::int2[]) WITH ORDINALITY AS k(attnum, ord)'
            . ' LEFT JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = k.attnum AND k.attnum > 0'
            . ' WHERE n.nspname = current_schema() AND c.relname = :t AND NOT ix.indisprimary'
            . " AND NOT EXISTS (SELECT 1 FROM pg_constraint con WHERE con.conindid = ix.indexrelid AND con.contype IN ('p', 'u', 'x'))"
            . ' ORDER BY i.relname, k.ord',
            ['t' => $name],
        ) as $row) {
            $indexName = (string) $row['name'];
            $indexes[$indexName] ??= ['unique' => self::bool($row['is_unique']), 'where' => $row['predicate'], 'columns' => [], 'expression' => false];
            if ($row['col'] === null) {
                $indexes[$indexName]['expression'] = true;
            } else {
                $indexes[$indexName]['columns'][] = (string) $row['col'];
            }
        }
        foreach ($indexes as $indexName => $index) {
            $table->indexes[] = new Index(
                $index['columns'],
                $index['unique'],
                $indexName,
                true,
                $index['where'] !== null ? (string) $index['where'] : null,
                false,
                $index['expression'],
            );
        }

        foreach ($this->connection->fetchAll(
            'SELECT con.conname AS name, pg_get_constraintdef(con.oid) AS def FROM pg_constraint con'
            . ' JOIN pg_class c ON c.oid = con.conrelid JOIN pg_namespace n ON n.oid = c.relnamespace'
            . " WHERE n.nspname = current_schema() AND c.relname = :t AND con.contype = 'c' ORDER BY con.conname",
            ['t' => $name],
        ) as $row) {
            $definition = (string) preg_replace('/\s+NOT VALID$/i', '', trim((string) $row['def']));
            if (preg_match('/^CHECK\s*\((.*)\)$/is', $definition, $m) === 1) {
                $table->checks[] = new Check(trim($m[1]), (string) $row['name']);
            }
        }

        return $table;
    }

    /** Name of the primary key constraint (needed to drop it). */
    public function primaryKeyName(string $table): ?string
    {
        $name = $this->connection->fetchColumn(
            'SELECT con.conname FROM pg_constraint con JOIN pg_class c ON c.oid = con.conrelid JOIN pg_namespace n ON n.oid = c.relnamespace'
            . " WHERE n.nspname = current_schema() AND c.relname = :t AND con.contype = 'p'",
            ['t' => $table],
        );

        return is_string($name) ? $name : null;
    }

    /** @return list<string> */
    public function enumLabels(string $type): array
    {
        return array_map(
            fn (array $r) => (string) $r['label'],
            $this->connection->fetchAll(
                'SELECT e.enumlabel AS label FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid WHERE t.typname = :n ORDER BY e.enumsortorder',
                ['n' => $type],
            ),
        );
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
