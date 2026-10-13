<?php

declare(strict_types=1);

namespace Nesto\Orm\Schema\Introspection;

use Nesto\Orm\Schema\Check;
use Nesto\Orm\Schema\Column;
use Nesto\Orm\Schema\ForeignKey;
use Nesto\Orm\Schema\Index;
use Nesto\Orm\Schema\Normalizer;
use Nesto\Orm\Schema\Table;

/**
 * SQLite: PRAGMA table_xinfo / index_list / index_info / foreign_key_list, plus the CREATE TABLE text for
 * what PRAGMAs do not report (AUTOINCREMENT, COLLATE, CHECK constraints, constraint names).
 */
final class SqliteIntrospector extends Introspector
{
    /** Table name => unsupported feature that makes an automatic rebuild lossy (generated columns, ...). */
    public array $unsupported = [];

    public function tableNames(): array
    {
        return array_map(
            fn (array $r) => (string) $r['name'],
            $this->connection->fetchAll("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"),
        );
    }

    public function viewNames(): array
    {
        return array_fill_keys(array_map(
            fn (array $r) => (string) $r['name'],
            $this->connection->fetchAll("SELECT name FROM sqlite_master WHERE type = 'view' ORDER BY name"),
        ), false);
    }

    public function createSql(string $table): ?string
    {
        $sql = $this->connection->fetchColumn("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :t", ['t' => $table]);

        return is_string($sql) ? $sql : null;
    }

    public function table(string $name): ?Table
    {
        $sql = $this->createSql($name);
        if ($sql === null) {
            return null;
        }
        $parts = self::parseCreateTable($sql);
        $table = new Table($name);
        $info = $this->connection->fetchAll('PRAGMA table_xinfo(' . $this->connection->quote($name) . ')');
        $primary = [];
        foreach ($info as $row) {
            if ((int) $row['hidden'] === 1) {
                $this->unsupported[$name] = 'hidden column "' . $row['name'] . '"'; // virtual tables
                continue;
            }
            if ((int) $row['pk'] > 0) {
                $primary[(int) $row['pk']] = (string) $row['name'];
            }
        }
        ksort($primary);
        $table->primaryKey = array_values($primary);
        $autoIncrement = count($primary) === 1 && preg_match('/\bAUTOINCREMENT\b/i', $sql) === 1;
        foreach ($info as $row) {
            if ((int) $row['hidden'] === 1) {
                continue;
            }
            $columnName = (string) $row['name'];
            $isPrimary = in_array($columnName, $table->primaryKey, true);
            $column = $this->column(
                $columnName,
                (string) $row['type'],
                (int) $row['notnull'] === 0 && ! $isPrimary,
                Normalizer::parseDefault($row['dflt_value'] !== null ? (string) $row['dflt_value'] : null),
                $autoIncrement && $isPrimary,
            );
            $column->primary = $isPrimary;
            $column->collation = $parts['collations'][strtolower($columnName)] ?? null;
            if (in_array((int) $row['hidden'], [2, 3], true)) {
                $column->generatedType = (int) $row['hidden'] === 3 ? 'STORED' : 'VIRTUAL';
                $column->asExpression = $parts['generated'][strtolower($columnName)] ?? '';
            }
            if (isset($parts['enums'][strtolower($columnName)])) {
                // the CHECK (col IN (...)) of an enum column
                $column = self::asEnum($column, $parts['enums'][strtolower($columnName)]);
            }
            $table->columns[$columnName] = $column;
        }

        foreach ($this->connection->fetchAll('PRAGMA index_list(' . $this->connection->quote($name) . ')') as $index) {
            if ($index['origin'] === 'pk') {
                continue;
            }
            $indexName = (string) $index['name'];
            $columns = array_column($this->connection->fetchAll('PRAGMA index_info(' . $this->connection->quote($indexName) . ')'), 'name');
            $expression = in_array(null, $columns, true);
            $indexSql = $this->connection->fetchColumn("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = :n", ['n' => $indexName]);
            $where = is_string($indexSql) && preg_match('/\bWHERE\b(.*)$/is', $indexSql, $m) === 1 ? trim($m[1]) : null;
            $isConstraint = $index['origin'] === 'u';
            $table->indexes[] = new Index(
                array_values(array_map('strval', array_filter($columns, fn ($c) => $c !== null))),
                (int) $index['unique'] === 1,
                $isConstraint ? null : $indexName,
                ! $isConstraint && ! str_starts_with($indexName, 'sqlite_'),
                $where,
                $isConstraint,
                $expression,
            );
        }

        $fks = [];
        foreach ($this->connection->fetchAll('PRAGMA foreign_key_list(' . $this->connection->quote($name) . ')') as $row) {
            $id = (int) $row['id'];
            $fks[$id] ??= ['table' => (string) $row['table'], 'from' => [], 'to' => [], 'on_update' => (string) $row['on_update'], 'on_delete' => (string) $row['on_delete']];
            $fks[$id]['from'][] = (string) $row['from'];
            $fks[$id]['to'][] = $row['to'];
        }
        ksort($fks);
        foreach ($fks as $fk) {
            $to = $fk['to'];
            if (in_array(null, $to, true)) {
                // REFERENCES t without columns: the target's primary key
                $to = array_values(array_map(
                    fn (array $r) => (string) $r['name'],
                    array_filter($this->connection->fetchAll('PRAGMA table_info(' . $this->connection->quote($fk['table']) . ')'), fn (array $r) => (int) $r['pk'] > 0),
                ));
            }
            $constraintName = $parts['foreignKeyNames'][strtolower(implode(',', $fk['from']))] ?? null;
            $table->foreignKeys[] = new ForeignKey(
                $fk['from'],
                $fk['table'],
                array_map('strval', $to),
                $fk['on_delete'] === 'NO ACTION' ? null : $fk['on_delete'],
                $fk['on_update'] === 'NO ACTION' ? null : $fk['on_update'],
                $constraintName,
                $constraintName !== null,
            );
        }
        foreach ($parts['checks'] as $check) {
            $table->checks[] = new Check($check['expression'], $check['name']);
        }

        return $table;
    }

    /**
     * What PRAGMAs do not tell from a CREATE TABLE statement: COLLATE per column, CHECK constraints (table and
     * column level, with their names) and the names of FOREIGN KEY constraints.
     *
     * @return array{collations: array<string, string>, checks: list<array{expression: string, name: ?string}>, foreignKeyNames: array<string, string>}
     */
    public static function parseCreateTable(string $sql): array
    {
        $result = ['collations' => [], 'checks' => [], 'foreignKeyNames' => [], 'generated' => [], 'enums' => []];
        $open = strpos($sql, '(');
        $close = strrpos($sql, ')');
        if ($open === false || $close === false) {
            return $result;
        }
        foreach (self::splitTopLevel(substr($sql, $open + 1, $close - $open - 1), ',') as $definition) {
            $definition = trim($definition);
            $constraintName = null;
            if (preg_match('/^CONSTRAINT\s+("(?:[^"]|"")+"|`[^`]+`|\[[^\]]+\]|\S+)\s+(.*)$/is', $definition, $m) === 1) {
                $constraintName = self::unquote($m[1]);
                $definition = $m[2];
            }
            if (preg_match('/^(PRIMARY\s+KEY|UNIQUE)\b/i', $definition) === 1) {
                continue;
            }
            if (preg_match('/^CHECK\s*\(/i', $definition) === 1) {
                $result['checks'][] = ['expression' => self::parenthesized($definition, (int) strpos($definition, '(')), 'name' => $constraintName];
                continue;
            }
            if (preg_match('/^FOREIGN\s+KEY\s*\(([^)]*)\)/i', $definition, $m) === 1) {
                if ($constraintName !== null) {
                    $columns = array_map(fn (string $c) => strtolower(self::unquote(trim($c))), explode(',', $m[1]));
                    $result['foreignKeyNames'][implode(',', $columns)] = $constraintName;
                }
                continue;
            }
            // column definition: name, then type and column constraints
            if (preg_match('/^("(?:[^"]|"")+"|`[^`]+`|\[[^\]]+\]|\S+)(.*)$/s', $definition, $m) !== 1) {
                continue;
            }
            $column = strtolower(self::unquote($m[1]));
            $rest = $m[2];
            if (preg_match('/\bCOLLATE\s+("(?:[^"]|"")+"|\S+)/i', $rest, $c) === 1) {
                $result['collations'][$column] = self::unquote($c[1]);
            }
            if (preg_match('/\b(?:GENERATED\s+ALWAYS\s+)?AS\s*\(/i', $rest, $g, PREG_OFFSET_CAPTURE) === 1) {
                $result['generated'][$column] = self::parenthesized($rest, $g[0][1] + strlen($g[0][0]) - 1);
            }
            $offset = 0;
            while (preg_match('/(?:\bCONSTRAINT\s+("(?:[^"]|"")+"|\S+)\s+)?\bCHECK\s*\(/i', $rest, $c, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $start = $c[0][1] + strlen($c[0][0]) - 1;
                $expression = self::parenthesized($rest, $start);
                $offset = $start + strlen($expression) + 2;
                $values = self::enumCheckValues($column, $expression);
                if ($values !== null && ! (isset($c[1]) && $c[1][1] >= 0)) {
                    $result['enums'][$column] = $values; // unnamed CHECK (col IN ('a', 'b')): an enum column
                    continue;
                }
                $result['checks'][] = ['expression' => $expression, 'name' => isset($c[1]) && $c[1][1] >= 0 ? self::unquote($c[1][0]) : null];
            }
        }

        return $result;
    }

    /** Values of a column CHECK of the form `"col" IN ('a', 'b')` on that same column, else null. @return ?list<string> */
    private static function enumCheckValues(string $column, string $expression): ?array
    {
        if (preg_match('/^("(?:[^"]|"")+"|`[^`]+`|\[[^\]]+\]|\w+)\s+IN\s*\((.*)\)$/is', trim($expression), $m) !== 1 || strtolower(self::unquote($m[1])) !== $column) {
            return null;
        }
        if (preg_match('/^\s*\'(?:[^\']|\'\')*\'(\s*,\s*\'(?:[^\']|\'\')*\')*\s*$/s', $m[2]) !== 1) {
            return null;
        }
        preg_match_all("/'((?:[^']|'')*)'/s", $m[2], $values);

        return array_map(fn (string $v) => str_replace("''", "'", $v), $values[1]);
    }

    private static function asEnum(Column $column, array $values): Column
    {
        $enum = new Column($column->name, 'enum', $column->length, $column->precision, $column->scale);
        foreach (get_object_vars($column) as $property => $value) {
            if (! in_array($property, ['name', 'type', 'length', 'precision', 'scale'], true)) {
                $enum->{$property} = $value;
            }
        }
        $enum->values = $values;

        return $enum;
    }

    /** The text inside the parentheses opening at $open. */
    private static function parenthesized(string $sql, int $open): string
    {
        $depth = 0;
        $quote = null;
        $length = strlen($sql);
        for ($i = $open; $i < $length; $i++) {
            $char = $sql[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                return trim(substr($sql, $open + 1, $i - $open - 1));
            }
        }

        return trim(substr($sql, $open + 1));
    }

    /** @return list<string> */
    private static function splitTopLevel(string $sql, string $separator): array
    {
        $parts = [];
        $depth = 0;
        $quote = null;
        $current = '';
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($quote !== null) {
                $current .= $char;
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === $separator && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    private static function unquote(string $identifier): string
    {
        $identifier = trim($identifier);
        $first = $identifier[0] ?? '';

        return match ($first) {
            '"' => str_replace('""', '"', substr($identifier, 1, -1)),
            '`' => substr($identifier, 1, -1),
            '[' => substr($identifier, 1, -1),
            default => $identifier,
        };
    }
}
