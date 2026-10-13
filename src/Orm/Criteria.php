<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Closure;
use Nesto\Orm\Mapping\PgArray;
use Nesto\Orm\Mapping\TypeConverter;
use Nesto\Orm\Operators\FindOperator;

/**
 * Compiles the array "where" shorthand used by Repository::find() and QueryBuilder::where(array):
 *
 *   ['status' => 'active']                       // =
 *   ['age' => ['>', 18]]                         // any of = != <> > >= < <=
 *   ['id' => ['In', [1, 2, 3]]]  or ['id' => [1, 2, 3]]
 *   ['name' => ['Like', 'A%']]  ['name' => ['NotLike', 'A%']]  ['name' => ['ILike', 'a%']]
 *   ['age' => ['Between', 18, 30]]
 *   ['deletedAt' => ['IsNull']]  / ['IsNotNull']  / null  (=> IS NULL)
 *   ['status' => ['Not', 'banned']]  ['id' => ['Not', [1, 2]]]  (NOT IN)
 *   ['user' => $user]  or ['user' => 5]          // relation property → its foreign key column
 *   ['commentable' => $post]                     // MorphTo → type alias AND id; null → both IS NULL;
 *   ['commentable' => [$post, $video]]           //   a list of entities is OR-ed
 *   ['author' => ['name' => 'Ada']]              // condition on a relation's columns (LEFT JOIN, nests)
 *   ['address' => ['city' => 'Oslo']]            // #[Embedded] columns (nests)
 *   [ [...], [...] ]                             // a list of criteria is OR-ed together
 *
 * TypeORM's find operators (Nesto\Orm\Operators): ['age' => MoreThan(18)], ['id' => Not(In([1, 2]))],
 * ['age' => Operator::and(MoreThan(18), LessThan(65))], ['name' => Raw(fn ($c) => "LOWER($c) = :n", ['n' => 'ada'])].
 * Values go through the column's type conversion and value transformers (LIKE patterns: transformers only).
 */
final class Criteria
{
    private const OPERATORS = ['=', '!=', '<>', '>', '>=', '<', '<=', 'in', 'notin', 'like', 'notlike', 'ilike', 'between', 'isnull', 'isnotnull', 'not'];

    /**
     * @param Closure(mixed): string $param registers a value and returns its ":placeholder"
     * @param Closure(string): string $column maps a property/column name to a quoted SQL reference
     * @param ?Closure(string): array{0: ?EntityMetadata, 1: Closure, 2: ?Closure} $join joins a relation property and
     *        returns the [meta, column, join] scope of its alias (nested relation conditions); null: unsupported
     */
    public static function compile(array $where, ?EntityMetadata $meta, Closure $column, Closure $param, string $driver = 'sqlite', ?Closure $join = null): string
    {
        if ($where === []) {
            return '1 = 1';
        }
        if (array_is_list($where)) {
            $parts = array_map(fn (array $w) => '(' . self::compile($w, $meta, $column, $param, $driver, $join) . ')', $where);

            return implode(' OR ', $parts);
        }
        $parts = [];
        foreach ($where as $key => $value) {
            $parts[] = self::condition((string) $key, $value, $meta, $column, $param, $driver, $join);
        }

        return implode(' AND ', $parts);
    }

    private static function condition(string $key, mixed $value, ?EntityMetadata $meta, Closure $column, Closure $param, string $driver, ?Closure $join): string
    {
        if ($meta !== null && is_array($value) && $value !== [] && ! array_is_list($value) && $meta->column($key) === null && $meta->embeddedColumns($key) !== []) {
            $parts = [];
            foreach ($value as $property => $sub) {
                $parts[] = self::condition($key . '.' . $property, $sub, $meta, $column, $param, $driver, $join);
            }

            return '(' . implode(' AND ', $parts) . ')';
        }
        $relation = $meta?->relation($key);
        if ($relation !== null && is_array($value) && ! array_is_list($value)) {
            if ($join === null) {
                throw new OrmException("Conditions on the columns of relation \${$relation->property} need an entity query (Repository find or QueryBuilder).");
            }
            if ($relation->type === Mapping\RelationType::MorphTo) {
                throw new OrmException("MorphTo \${$relation->property} cannot be filtered by its columns (its table depends on the row): pass an entity, a list of entities or null.");
            }
            [$subMeta, $subColumn, $subJoin] = $join($key);

            return '(' . self::compile($value, $subMeta, $subColumn, $param, $driver, $subJoin) . ')';
        }
        if ($relation !== null && $relation->type === Mapping\RelationType::MorphTo) {
            return self::morphCondition($relation, $value, $column, $param);
        }
        $col = $column($key);
        $convert = fn (mixed $v): mixed => self::convert($meta, $key, $v);
        if ($value instanceof FindOperator) {
            return self::operator($col, $value, $meta, $key, $param, $driver);
        }

        if (self::isEntity($value)) {
            $value = self::primaryOf($value);
        }
        if ($value === null) {
            return "$col IS NULL";
        }
        if (is_array($value)) {
            $op = isset($value[0]) && is_string($value[0]) ? strtolower(str_replace([' ', '_'], '', $value[0])) : null;
            if ($op === null || ! in_array($op, self::OPERATORS, true)) {
                return self::inList($col, array_map($convert, array_values($value)), $param);
            }
            $operand = $value[1] ?? null;
            if (self::isEntity($operand)) {
                $operand = self::primaryOf($operand);
            }

            return match ($op) {
                'isnull' => "$col IS NULL",
                'isnotnull' => "$col IS NOT NULL",
                'in' => self::inList($col, array_map($convert, array_values((array) $operand)), $param),
                'notin' => self::inList($col, array_map($convert, array_values((array) $operand)), $param, true),
                'like' => "$col LIKE " . $param($operand),
                'notlike' => "$col NOT LIKE " . $param($operand),
                'ilike' => $driver === 'pgsql' ? "$col ILIKE " . $param($operand) : "LOWER($col) LIKE LOWER(" . $param($operand) . ')',
                'between' => "$col BETWEEN " . $param($convert($operand)) . ' AND ' . $param($convert($value[2] ?? null)),
                'not' => $operand === null
                    ? "$col IS NOT NULL"
                    : (is_array($operand)
                        ? self::inList($col, array_map($convert, array_values($operand)), $param, true)
                        : "$col != " . $param($convert($operand))),
                default => "$col $op " . $param($convert($operand)),
            };
        }

        return "$col = " . $param($convert($value));
    }

    private static function morphCondition(Mapping\RelationMetadata $relation, mixed $value, Closure $column, Closure $param): string
    {
        $type = $column((string) $relation->morphTypeColumn);
        $id = $column((string) $relation->morphIdColumn);
        if ($value === null) {
            return "($type IS NULL AND $id IS NULL)";
        }
        $targets = is_array($value) && array_is_list($value) ? $value : [$value];
        $parts = [];
        foreach ($targets as $target) {
            if (! is_object($target)) {
                throw new OrmException("Criteria on MorphTo \${$relation->property} expects an entity, a list of entities or null.");
            }
            $parts[] = "($type = " . $param(MorphMap::aliasFor($target::class)) . " AND $id = " . $param(self::primaryOf($target)) . ')';
        }

        return $parts === [] ? '1 = 0' : '(' . implode(' OR ', $parts) . ')';
    }

    private static function inList(string $col, array $values, Closure $param, bool $not = false): string
    {
        if ($values === []) {
            return $not ? '1 = 1' : '1 = 0';
        }
        $placeholders = array_map($param, $values);

        return $col . ($not ? ' NOT IN (' : ' IN (') . implode(', ', $placeholders) . ')';
    }

    /**
     * SQL of a find operator on a column reference.
     */
    private static function operator(string $col, FindOperator $op, ?EntityMetadata $meta, string $key, Closure $param, string $driver): string
    {
        $convert = fn (mixed $v): mixed => self::convert($meta, $key, $v);
        $value = $op->value;
        $compare = fn (string $sqlOperator): string => "$col $sqlOperator " . $param($convert($value));

        return match ($op->type) {
            'not' => match (true) {
                $value instanceof FindOperator => $value->type === 'isNull' ? "$col IS NOT NULL" : 'NOT(' . self::operator($col, $value, $meta, $key, $param, $driver) . ')',
                $value === null => "$col IS NOT NULL",
                is_array($value) && array_is_list($value) => self::inList($col, array_map($convert, $value), $param, true),
                default => $compare('!='),
            },
            'lessThan' => $compare('<'),
            'lessThanOrEqual' => $compare('<='),
            'moreThan' => $compare('>'),
            'moreThanOrEqual' => $compare('>='),
            'equal' => $value === null ? "$col IS NULL" : $compare('='),
            'like' => "$col LIKE " . $param(self::transformOnly($meta, $key, $value)),
            'ilike' => $driver === 'pgsql'
                ? "$col ILIKE " . $param(self::transformOnly($meta, $key, $value))
                : "LOWER($col) LIKE LOWER(" . $param(self::transformOnly($meta, $key, $value)) . ')',
            'between' => "$col BETWEEN " . $param($convert($value[0] ?? null)) . ' AND ' . $param($convert($value[1] ?? null)),
            'in' => self::inList($col, array_map($convert, (array) $value), $param),
            'any' => $driver === 'pgsql'
                ? "$col = ANY(" . $param(PgArray::format(array_map($convert, (array) $value))) . ')'
                : self::inList($col, array_map($convert, (array) $value), $param),
            'isNull' => "$col IS NULL",
            'raw' => self::raw($col, $op, $param),
            'and', 'or' => '(' . implode(
                $op->type === 'and' ? ' AND ' : ' OR ',
                array_map(fn (FindOperator $child) => self::operator($col, $child, $meta, $key, $param, $driver), $op->children()),
            ) . ')',
            'arrayContains', 'arrayContainedBy', 'arrayOverlap' => "$col " . ['arrayContains' => '@>', 'arrayContainedBy' => '<@', 'arrayOverlap' => '&&'][$op->type]
                . ' ' . $param(self::arrayOperand($meta, $key, $op, $driver)),
            'jsonContains' => match ($driver) {
                'pgsql' => "$col @> CAST(" . $param(self::json($value)) . ' AS jsonb)',
                'mysql' => "JSON_CONTAINS($col, " . $param(self::json($value)) . ')',
                default => throw new OrmException('JsonContains() needs PostgreSQL, MySQL or MariaDB.'),
            },
        };
    }

    /** Raw(): a string is `column = <sql>`, a closure builds the condition; `:name` / `:...name` bind $parameters. */
    private static function raw(string $col, FindOperator $op, Closure $param): string
    {
        $sql = $op->value instanceof Closure ? (string) ($op->value)($col) : "$col = " . $op->value;
        if ($op->parameters === []) {
            return $sql;
        }

        return (string) preg_replace_callback('/(?<![:\w]):(\.\.\.)?([A-Za-z_][A-Za-z0-9_]*)/', function (array $m) use ($op, $param): string {
            if (! array_key_exists($m[2], $op->parameters)) {
                return $m[0];
            }
            $value = $op->parameters[$m[2]];
            if ($m[1] !== '') {
                return implode(', ', array_map(fn (mixed $v) => $param(TypeConverter::toParameter($v)), array_values((array) $value)));
            }

            return $param(TypeConverter::toParameter($value));
        }, $sql);
    }

    private static function arrayOperand(?EntityMetadata $meta, string $key, FindOperator $op, string $driver): string
    {
        if ($driver !== 'pgsql') {
            throw new OrmException(ucfirst($op->type) . '() needs PostgreSQL (array columns).');
        }
        $column = $meta?->column($key);
        if ($column !== null && ! $column->array) {
            throw new OrmException(ucfirst($op->type) . "() on \"$key\": the column is not an array column (#[Column(array: true)]).");
        }

        return $column !== null ? (string) TypeConverter::toDatabase($column, (array) $op->value) : PgArray::format((array) $op->value);
    }

    private static function json(mixed $value): string
    {
        return is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private static function transformOnly(?EntityMetadata $meta, string $key, mixed $value): mixed
    {
        $column = $meta?->column($key);

        return $column !== null ? TypeConverter::transform($column, $value) : $value;
    }

    private static function convert(?EntityMetadata $meta, string $key, mixed $value): mixed
    {
        if (self::isEntity($value)) {
            $value = self::primaryOf($value);
        }
        $column = $meta?->column($key);
        if ($column === null && $meta !== null) {
            $relation = $meta->relation($key);
            if ($relation?->joinColumn !== null) {
                $column = $meta->columns[$relation->joinColumn] ?? null;
            }
        }

        return $column !== null ? TypeConverter::toDatabase($column, $value) : TypeConverter::toParameter($value);
    }

    /** An entity instance (stands for its primary key); other objects are values (enums, dates, value objects). */
    private static function isEntity(mixed $value): bool
    {
        return is_object($value) && ! $value instanceof \BackedEnum && ! $value instanceof \DateTimeInterface
            && ! $value instanceof FindOperator && MetadataRegistry::has($value::class);
    }

    private static function primaryOf(object $entity): mixed
    {
        $meta = MetadataRegistry::get($entity::class);

        return TypeConverter::toDatabase($meta->primary, $meta->primaryValue($entity));
    }
}
