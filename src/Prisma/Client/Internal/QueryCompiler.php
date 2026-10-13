<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Internal;

use Nesto\Prisma\Client\Errors\PrismaClientValidationError;
use Nesto\Prisma\Client\NullType;
use Nesto\Prisma\Client\Prisma;
use Nesto\Prisma\Dmmf\Field;

/**
 * Compiles Prisma's `where` and `orderBy` inputs to SQL for one statement. Parameters are positional (`?`):
 * every method appends to the `$params` list it is given, in the order its placeholders appear in the SQL it
 * returns. Table aliases (t0, t1, ...) are unique within the compiler, so one compiler builds one statement.
 *
 * Relation filters (some / every / none / is / isNot) are correlated EXISTS subqueries; relation orderBy
 * uses scalar subqueries — both work the same on PostgreSQL, MySQL, MariaDB and SQLite.
 *
 * @internal
 */
final class QueryCompiler
{
    private const SCALAR_OPS = ['equals', 'not', 'in', 'notIn', 'lt', 'lte', 'gt', 'gte', 'contains', 'startsWith', 'endsWith', 'mode', 'search'];
    private const LIST_OPS = ['equals', 'has', 'hasEvery', 'hasSome', 'isEmpty'];
    private const JSON_OPS = ['equals', 'not', 'path', 'string_contains', 'string_starts_with', 'string_ends_with', 'array_contains', 'array_starts_with', 'array_ends_with', 'lt', 'lte', 'gt', 'gte', 'mode'];

    private int $aliases = 0;

    public function __construct(private readonly Engine $engine) {}

    public function alias(): string
    {
        return 't' . $this->aliases++;
    }

    public function q(string $identifier): string
    {
        return $this->engine->quote($identifier);
    }

    public function col(string $alias, ModelInfo $model, string $field): string
    {
        return $alias . '.' . $this->q($model->column($field));
    }

    public function table(ModelInfo $model): string
    {
        return $this->q($model->table);
    }

    // ---- where -----------------------------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $where
     * @param list<mixed>               $params
     */
    public function where(ModelInfo $model, ?array $where, string $alias, array &$params): string
    {
        if ($where === null || $where === []) {
            return '1=1';
        }
        $parts = [];
        foreach ($where as $key => $value) {
            $key = (string) $key;
            if ($key === 'AND' || $key === 'OR') {
                $items = [];
                foreach ($this->listOf($value) as $w) {
                    $items[] = $this->where($model, $this->objectArg($w, $key), $alias, $params);
                }
                $parts[] = $items === [] ? ($key === 'AND' ? '1=1' : '1=0') : '(' . implode(' ' . $key . ' ', $items) . ')';
            } elseif ($key === 'NOT') {
                foreach ($this->listOf($value) as $w) {
                    // like Prisma, a condition that is NULL (unknown) counts as false before the negation
                    $parts[] = 'NOT COALESCE((' . $this->where($model, $this->objectArg($w, 'NOT'), $alias, $params) . '), FALSE)';
                }
            } elseif (($field = $model->scalar($key)) !== null) {
                $parts[] = $this->fieldFilter($field, $this->col($alias, $model, $key), $value, $params);
            } elseif (($relation = $model->relation($key)) !== null) {
                $parts[] = $this->relationFilter($model, $relation, $alias, $value, $params);
            } elseif (isset($model->compoundKeys[$key])) {
                if (! is_array($value)) {
                    throw new PrismaClientValidationError(sprintf('Argument `%s` of `%sWhereUniqueInput` must be an object with the fields %s.', $key, $model->name, implode(', ', $model->compoundKeys[$key])));
                }
                foreach ($model->compoundKeys[$key] as $name) {
                    if (! array_key_exists($name, $value)) {
                        throw new PrismaClientValidationError(sprintf('Argument `%s` is missing in `%s.%s`.', $name, $model->name, $key));
                    }
                    $parts[] = $this->compare($model->scalars[$name], $this->col($alias, $model, $name), '=', $value[$name], $params);
                }
            } else {
                throw new PrismaClientValidationError(sprintf('Unknown argument `%s` in where of model `%s`. Available options: %s.', $key, $model->name, implode(', ', [...array_keys($model->scalars), ...array_keys($model->relations), 'AND', 'OR', 'NOT'])));
            }
        }

        return $parts === [] ? '1=1' : implode(' AND ', $parts);
    }

    /** @return list<mixed> */
    private function listOf(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        return is_array($value) && array_is_list($value) ? $value : [$value];
    }

    /** @return array<string, mixed> */
    private function objectArg(mixed $value, string $operator): array
    {
        if (! is_array($value)) {
            throw new PrismaClientValidationError("Argument `$operator` must be a where object or a list of where objects.");
        }

        return $value;
    }

    /** @param list<mixed> $params */
    public function fieldFilter(Field $field, string $expr, mixed $value, array &$params): string
    {
        if ($field->isList) {
            return $this->listFilter($field, $expr, $value, $params);
        }
        if ($field->type === 'Json' && $field->kind === Field::SCALAR) {
            return $this->jsonFilter($field, $expr, $value, $params);
        }
        if (! is_array($value)) {
            return $this->compare($field, $expr, '=', $value, $params);
        }
        $this->assertOps($value, self::SCALAR_OPS, $field);
        $insensitive = ($value['mode'] ?? 'default') === 'insensitive';
        $parts = [];
        foreach ($value as $op => $operand) {
            $parts[] = match ($op) {
                'equals' => $this->compare($field, $expr, '=', $operand, $params, $insensitive),
                'not' => is_array($operand)
                    ? 'NOT (' . $this->fieldFilter($field, $expr, $operand + ($insensitive ? ['mode' => 'insensitive'] : []), $params) . ')'
                    : $this->compare($field, $expr, '<>', $operand, $params, $insensitive),
                'in', 'notIn' => $this->in($field, $expr, $operand, $op === 'notIn', $params, $insensitive),
                'lt' => $this->compare($field, $expr, '<', $operand, $params, $insensitive),
                'lte' => $this->compare($field, $expr, '<=', $operand, $params, $insensitive),
                'gt' => $this->compare($field, $expr, '>', $operand, $params, $insensitive),
                'gte' => $this->compare($field, $expr, '>=', $operand, $params, $insensitive),
                'contains' => $this->like($expr, '%' . $this->escapeLike((string) $operand) . '%', $params, $insensitive),
                'startsWith' => $this->like($expr, $this->escapeLike((string) $operand) . '%', $params, $insensitive),
                'endsWith' => $this->like($expr, '%' . $this->escapeLike((string) $operand), $params, $insensitive),
                'mode' => null,
                'search' => throw new PrismaClientValidationError('Full-text `search` is not supported by the Nesto client.'),
            };
        }
        $parts = array_values(array_filter($parts, fn ($p) => $p !== null));

        return $parts === [] ? '1=1' : implode(' AND ', $parts);
    }

    /** @param array<mixed> $value @param list<string> $allowed */
    private function assertOps(array $value, array $allowed, Field $field): void
    {
        foreach (array_keys($value) as $op) {
            if (! in_array($op, $allowed, true)) {
                throw new PrismaClientValidationError(sprintf('Unknown filter `%s` on field `%s` (%s%s). Available: %s.', $op, $field->name, $field->type, $field->isList ? '[]' : '', implode(', ', $allowed)));
            }
        }
    }

    /** @param list<mixed> $params */
    public function compare(Field $field, string $expr, string $op, mixed $value, array &$params, bool $insensitive = false): string
    {
        if ($value === null || $value === NullType::DbNull) {
            return match ($op) {
                '=' => "$expr IS NULL",
                '<>' => "$expr IS NOT NULL",
                default => throw new PrismaClientValidationError(sprintf('Argument `%s` of field `%s` must not be null.', $op, $field->name)),
            };
        }
        $params[] = $this->engine->codec->encodeScalar($field, $value);
        if ($insensitive) {
            return "LOWER($expr) $op LOWER(?)";
        }

        return "$expr $op ?";
    }

    /** @param list<mixed> $params */
    private function in(Field $field, string $expr, mixed $values, bool $negate, array &$params, bool $insensitive): string
    {
        if (! is_array($values)) {
            $values = [$values];
        }
        $values = array_values(array_filter($values, fn ($v) => $v !== null));
        if ($values === []) {
            return $negate ? '1=1' : '1=0';
        }
        $placeholders = [];
        foreach ($values as $value) {
            $params[] = $this->engine->codec->encodeScalar($field, $value);
            $placeholders[] = $insensitive ? 'LOWER(?)' : '?';
        }
        $target = $insensitive ? "LOWER($expr)" : $expr;

        return $target . ($negate ? ' NOT IN (' : ' IN (') . implode(', ', $placeholders) . ')';
    }

    public function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** @param list<mixed> $params */
    private function like(string $expr, string $pattern, array &$params, bool $insensitive): string
    {
        $params[] = $pattern;
        $escape = $this->engine->platform === 'sqlite' ? " ESCAPE '\\'" : '';
        if (! $insensitive) {
            return "$expr LIKE ?$escape";
        }

        return $this->engine->platform === 'pgsql' ? "$expr ILIKE ?" : "LOWER($expr) LIKE LOWER(?)$escape";
    }

    /** PostgreSQL scalar lists: equals, has, hasEvery, hasSome, isEmpty. @param list<mixed> $params */
    private function listFilter(Field $field, string $expr, mixed $value, array &$params): string
    {
        if ($value === null) {
            return "$expr IS NULL";
        }
        if (! is_array($value) || array_is_list($value)) {
            $value = ['equals' => $value];
        }
        $this->assertOps($value, self::LIST_OPS, $field);
        $codec = $this->engine->codec;
        $parts = [];
        foreach ($value as $op => $operand) {
            switch ($op) {
                case 'equals':
                    if ($operand === null) {
                        $parts[] = "$expr IS NULL";
                        break;
                    }
                    $params[] = $codec->encodeList($field, (array) $operand);
                    $parts[] = "$expr = ?";
                    break;
                case 'has':
                    if ($operand === null) {
                        $parts[] = '1=0';
                        break;
                    }
                    $params[] = $codec->encodeScalar($field, $operand);
                    $parts[] = "? = ANY($expr)";
                    break;
                case 'hasEvery':
                case 'hasSome':
                    $params[] = $codec->encodeList($field, (array) $operand);
                    $parts[] = $expr . ($op === 'hasEvery' ? ' @> ?' : ' && ?');
                    break;
                case 'isEmpty':
                    $parts[] = $operand ? "COALESCE(cardinality($expr), 0) = 0" : "cardinality($expr) > 0";
                    break;
            }
        }

        return $parts === [] ? '1=1' : implode(' AND ', $parts);
    }

    // ---- Json ------------------------------------------------------------------------------------

    /** @param list<mixed> $params */
    private function jsonFilter(Field $field, string $expr, mixed $value, array &$params): string
    {
        if (! is_array($value) || array_diff(array_keys($value), self::JSON_OPS) !== [] || $value === []) {
            $value = ['equals' => $value];
        }
        $path = $value['path'] ?? null;
        $segments = $path === null ? [] : $this->jsonPath($path);
        $insensitive = ($value['mode'] ?? 'default') === 'insensitive';
        $parts = [];
        foreach ($value as $op => $operand) {
            switch ($op) {
                case 'path':
                case 'mode':
                    break;
                case 'equals':
                    $parts[] = $this->jsonEquals($expr, $segments, $operand, $params);
                    break;
                case 'not':
                    $parts[] = $operand === null || $operand === NullType::DbNull
                        ? ($segments === [] ? "$expr IS NOT NULL" : $this->jsonExtract($expr, $segments) . ' IS NOT NULL')
                        : 'NOT (' . $this->jsonEquals($expr, $segments, $operand, $params) . ')';
                    break;
                case 'string_contains':
                    $parts[] = $this->like($this->jsonText($expr, $segments), '%' . $this->escapeLike((string) $operand) . '%', $params, $insensitive);
                    break;
                case 'string_starts_with':
                    $parts[] = $this->like($this->jsonText($expr, $segments), $this->escapeLike((string) $operand) . '%', $params, $insensitive);
                    break;
                case 'string_ends_with':
                    $parts[] = $this->like($this->jsonText($expr, $segments), '%' . $this->escapeLike((string) $operand), $params, $insensitive);
                    break;
                case 'gt':
                case 'gte':
                case 'lt':
                case 'lte':
                    $sql = ['gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$op];
                    if (is_int($operand) || is_float($operand)) {
                        $left = $this->engine->platform === 'pgsql' ? 'CAST(' . $this->jsonText($expr, $segments) . ' AS NUMERIC)' : $this->jsonScalar($expr, $segments);
                    } else {
                        $left = $this->jsonText($expr, $segments);
                        $operand = $operand instanceof \DateTimeInterface ? $operand->format(DATE_ATOM) : (string) $operand;
                    }
                    $params[] = $operand;
                    $parts[] = "$left $sql ?";
                    break;
                case 'array_contains':
                    $parts[] = $this->jsonArrayContains($expr, $segments, $operand, $params);
                    break;
                case 'array_starts_with':
                case 'array_ends_with':
                    $element = $this->jsonElement($expr, $segments, $op === 'array_starts_with');
                    $parts[] = $this->jsonEqualsExpr($element, $operand, $params);
                    break;
            }
        }

        return $parts === [] ? '1=1' : implode(' AND ', $parts);
    }

    /** @return list<string|int> */
    private function jsonPath(mixed $path): array
    {
        if (is_array($path)) {
            return array_values(array_map(fn ($s) => is_int($s) || ctype_digit((string) $s) ? (int) $s : (string) $s, $path));
        }
        $path = (string) $path;
        if (! str_starts_with($path, '$')) {
            return [$path];
        }
        preg_match_all('/\.(?:"([^"]*)"|([^.\[]+))|\[(\d+)\]/', substr($path, 1), $matches, PREG_SET_ORDER);
        $segments = [];
        foreach ($matches as $m) {
            $segments[] = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : ($m[1] !== '' ? $m[1] : $m[2]);
        }

        return $segments;
    }

    /** "$.a[0].b" for MySQL / MariaDB / SQLite. @param list<string|int> $segments */
    private function jsonPathString(array $segments): string
    {
        $path = '$';
        foreach ($segments as $segment) {
            $path .= is_int($segment) ? '[' . $segment . ']' : '."' . str_replace('"', '\\"', $segment) . '"';
        }

        return $path;
    }

    /** The JSON value at the path (JSON type / JSON text). @param list<string|int> $segments */
    private function jsonExtract(string $expr, array $segments): string
    {
        if ($segments === []) {
            return $this->engine->platform === 'pgsql' ? "CAST($expr AS JSONB)" : $expr;
        }
        $quoted = $this->literal($this->jsonPathString($segments));

        return match ($this->engine->platform) {
            'pgsql' => "(CAST($expr AS JSONB) #> " . $this->literal($this->pgPath($segments)) . ')',
            'sqlite' => "($expr -> $quoted)",
            default => "JSON_EXTRACT($expr, $quoted)",
        };
    }

    /** The value at the path as unquoted text (strings without quotes). @param list<string|int> $segments */
    private function jsonText(string $expr, array $segments): string
    {
        $quoted = $this->literal($this->jsonPathString($segments));

        return match ($this->engine->platform) {
            'pgsql' => "(CAST($expr AS JSONB) #>> " . $this->literal($this->pgPath($segments)) . ')',
            'sqlite' => "json_extract($expr, $quoted)",
            default => "JSON_UNQUOTE(JSON_EXTRACT($expr, $quoted))",
        };
    }

    /** The value at the path as an SQL scalar (numbers compare as numbers). @param list<string|int> $segments */
    private function jsonScalar(string $expr, array $segments): string
    {
        $quoted = $this->literal($this->jsonPathString($segments));

        return match ($this->engine->platform) {
            'sqlite' => "json_extract($expr, $quoted)",
            'mariadb' => "JSON_VALUE($expr, $quoted)",
            default => "JSON_EXTRACT($expr, $quoted)",
        };
    }

    /** @param list<string|int> $segments */
    private function pgPath(array $segments): string
    {
        return '{' . implode(',', array_map(fn ($s) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $s) . '"', $segments)) . '}';
    }

    private function literal(string $value): string
    {
        return "'" . str_replace("'", "''", $this->engine->platform === 'pgsql' || $this->engine->platform === 'sqlite' ? $value : str_replace('\\', '\\\\', $value)) . "'";
    }

    /** @param list<string|int> $segments @param list<mixed> $params */
    private function jsonEquals(string $expr, array $segments, mixed $operand, array &$params): string
    {
        if ($operand === null || $operand === NullType::DbNull) {
            return $segments === [] ? "$expr IS NULL" : $this->jsonExtract($expr, $segments) . ' IS NULL';
        }
        if ($operand === NullType::AnyNull) {
            $extract = $this->jsonExtract($expr, $segments);

            return "($extract IS NULL OR " . $this->jsonEqualsExpr($extract, Prisma::JsonNull, $params) . ')';
        }

        return $this->jsonEqualsExpr($this->jsonExtract($expr, $segments), $operand, $params);
    }

    /** JSON value $left equals the PHP value $operand. @param list<mixed> $params */
    private function jsonEqualsExpr(string $left, mixed $operand, array &$params): string
    {
        $params[] = $this->engine->codec->jsonIn($operand);

        return match ($this->engine->platform) {
            'pgsql' => "$left = CAST(? AS JSONB)",
            'mysql' => "$left = CAST(? AS JSON)",
            'mariadb' => "(CASE WHEN $left IS NULL THEN NULL ELSE JSON_EQUALS($left, ?) END)", // JSON_EQUALS(NULL, x) is not NULL on MariaDB
            default => "json($left) = json(?)",
        };
    }

    /** @param list<string|int> $segments @param list<mixed> $params */
    private function jsonArrayContains(string $expr, array $segments, mixed $operand, array &$params): string
    {
        $values = is_array($operand) && array_is_list($operand) ? $operand : [$operand];
        if ($this->engine->platform === 'sqlite') {
            $parts = [];
            $path = $this->literal($this->jsonPathString($segments));
            foreach ($values as $value) {
                $params[] = $this->engine->codec->jsonIn($value);
                $parts[] = "EXISTS (SELECT 1 FROM json_each($expr, $path) je WHERE (CASE WHEN je.type IN ('object', 'array') THEN json(je.value) ELSE json_quote(je.value) END) = json(?))";
            }

            return $parts === [] ? '1=1' : '(' . implode(' AND ', $parts) . ')';
        }
        $params[] = $this->engine->codec->jsonIn($values);
        $left = $this->jsonExtract($expr, $segments);

        return $this->engine->platform === 'pgsql' ? "$left @> CAST(? AS JSONB)" : "JSON_CONTAINS($left, ?)";
    }

    /** First / last element of the array at the path. @param list<string|int> $segments */
    private function jsonElement(string $expr, array $segments, bool $first): string
    {
        return match ($this->engine->platform) {
            'pgsql' => '(' . $this->jsonExtract($expr, $segments) . ($first ? ' -> 0)' : ' -> -1)'),
            'sqlite' => "($expr -> " . $this->literal($this->jsonPathString($segments) . ($first ? '[0]' : '[#-1]')) . ')',
            'mysql' => 'JSON_EXTRACT(' . $expr . ', ' . $this->literal($this->jsonPathString($segments) . ($first ? '[0]' : '[last]')) . ')',
            default => $first
                ? 'JSON_EXTRACT(' . $expr . ', ' . $this->literal($this->jsonPathString($segments) . '[0]') . ')'
                : 'JSON_EXTRACT(' . $expr . ', CONCAT(' . $this->literal($this->jsonPathString($segments)) . ", '[', JSON_LENGTH($expr, " . $this->literal($this->jsonPathString($segments)) . ") - 1, ']'))",
        };
    }

    // ---- relations -------------------------------------------------------------------------------

    /** @param list<mixed> $params */
    private function relationFilter(ModelInfo $model, RelationInfo $relation, string $alias, mixed $value, array &$params): string
    {
        if ($relation->isList()) {
            if (! is_array($value) || $value === [] || array_diff(array_keys($value), ['some', 'every', 'none']) !== []) {
                throw new PrismaClientValidationError(sprintf('The filter on the list relation `%s.%s` must use `some`, `every` or `none`.', $model->name, $relation->name()));
            }
            $parts = [];
            foreach ($value as $op => $where) {
                $where = $where === true ? [] : (array) $where;
                $parts[] = match ($op) {
                    'some' => $this->exists($model, $relation, $alias, $where, $params),
                    'none' => 'NOT ' . $this->exists($model, $relation, $alias, $where, $params),
                    'every' => $where === [] ? '1=1' : 'NOT ' . $this->exists($model, $relation, $alias, $where, $params, true),
                };
            }

            return implode(' AND ', $parts);
        }
        if ($value === null) {
            return 'NOT ' . $this->exists($model, $relation, $alias, [], $params);
        }
        if (! is_array($value)) {
            throw new PrismaClientValidationError(sprintf('The filter on the relation `%s.%s` must be an object.', $model->name, $relation->name()));
        }
        if ($value !== [] && array_diff(array_keys($value), ['is', 'isNot']) === []) {
            $parts = [];
            foreach ($value as $op => $where) {
                if ($where === null) {
                    $parts[] = ($op === 'is' ? 'NOT ' : '') . $this->exists($model, $relation, $alias, [], $params);
                } else {
                    $parts[] = ($op === 'isNot' ? 'NOT ' : '') . $this->exists($model, $relation, $alias, (array) $where, $params);
                }
            }

            return implode(' AND ', $parts);
        }

        return $this->exists($model, $relation, $alias, $value, $params);
    }

    /**
     * EXISTS (related rows of the row aliased $alias matching $where); with $negateWhere the rows NOT matching.
     *
     * @param array<string, mixed> $where
     * @param list<mixed>          $params
     */
    public function exists(ModelInfo $model, RelationInfo $relation, string $alias, array $where, array &$params, bool $negateWhere = false): string
    {
        $target = $this->engine->model($relation->target);
        $a = $this->alias();
        $condition = $this->where($target, $where, $a, $params);
        if ($negateWhere) {
            $condition = "NOT ($condition)";
        }

        return 'EXISTS (SELECT 1 FROM ' . $this->joinSource($model, $relation, $alias, $a) . ' AND ' . $condition . ')';
    }

    /**
     * "<target table> a WHERE <a joined to the row $alias>" (m2m: through the join table) — completed by the
     * caller with " AND ...".
     */
    public function joinSource(ModelInfo $model, RelationInfo $relation, string $alias, string $a): string
    {
        $target = $this->engine->model($relation->target);
        if ($relation->kind === RelationInfo::M2M) {
            $j = $this->alias();
            [$thisId, $otherId] = $relation->pairs[0];

            return $this->q((string) $relation->joinTable) . " $j INNER JOIN " . $this->table($target) . " $a ON $a." . $this->q($target->column($otherId))
                . " = $j." . $this->q((string) $relation->otherColumn) . " WHERE $j." . $this->q((string) $relation->thisColumn) . ' = ' . $this->col($alias, $model, $thisId);
        }
        $conditions = [];
        foreach ($relation->pairs as [$thisField, $otherField]) {
            $conditions[] = $this->col($a, $target, $otherField) . ' = ' . $this->col($alias, $model, $thisField);
        }

        return $this->table($target) . " $a WHERE " . implode(' AND ', $conditions);
    }

    // ---- orderBy ---------------------------------------------------------------------------------

    /**
     * @param array<mixed>|null $orderBy `['name' => 'asc']`, a list of those, `['name' => ['sort' => 'asc', 'nulls' => 'last']]`,
     *                                   `['author' => ['name' => 'asc']]`, `['posts' => ['_count' => 'desc']]`
     * @return list<array{0: string, 1: string, 2: ?string, 3: list<mixed>}> [expression, ASC|DESC, nulls first|last|null, params]
     */
    public function orderBy(ModelInfo $model, ?array $orderBy, string $alias): array
    {
        if ($orderBy === null || $orderBy === []) {
            return [];
        }
        $items = array_is_list($orderBy) ? $orderBy : [$orderBy];
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new PrismaClientValidationError('Argument `orderBy` must be an object or a list of objects.');
            }
            foreach ($item as $key => $spec) {
                foreach ($this->orderItem($model, (string) $key, $spec, $alias) as $entry) {
                    $out[] = $entry;
                }
            }
        }

        return $out;
    }

    /** @return list<array{0: string, 1: string, 2: ?string, 3: list<mixed>}> */
    private function orderItem(ModelInfo $model, string $key, mixed $spec, string $alias): array
    {
        if (($field = $model->scalar($key)) !== null) {
            [$dir, $nulls] = $this->direction($spec, $key);

            return [[$this->col($alias, $model, $field->name), $dir, $nulls, []]];
        }
        $relation = $model->relation($key);
        if ($relation === null) {
            throw new PrismaClientValidationError(sprintf('Unknown field `%s` in orderBy of model `%s`.', $key, $model->name));
        }
        if (! is_array($spec)) {
            throw new PrismaClientValidationError(sprintf('orderBy on the relation `%s` needs an object (`{ field: \'asc\' }` or `{ _count: \'asc\' }`).', $key));
        }
        $a = $this->alias();
        if ($relation->isList()) {
            if (array_keys($spec) !== ['_count']) {
                throw new PrismaClientValidationError(sprintf('Ordering by the list relation `%s` only supports `_count`.', $key));
            }
            [$dir, $nulls] = $this->direction($spec['_count'], '_count');
            if ($relation->kind === RelationInfo::M2M) {
                $j = $this->alias();
                $expr = '(SELECT COUNT(*) FROM ' . $this->q((string) $relation->joinTable) . " $j WHERE $j." . $this->q((string) $relation->thisColumn) . ' = ' . $this->col($alias, $model, $relation->pairs[0][0]) . ')';
            } else {
                $expr = '(SELECT COUNT(*) FROM ' . $this->joinSource($model, $relation, $alias, $a) . ')';
            }

            return [[$expr, $dir, $nulls, []]];
        }
        $target = $this->engine->model($relation->target);
        $out = [];
        foreach ($this->orderBy($target, $spec, $a) as [$inner, $dir, $nulls, $params]) {
            $out[] = ['(SELECT ' . $inner . ' FROM ' . $this->joinSource($model, $relation, $alias, $a) . ')', $dir, $nulls, $params];
        }

        return $out;
    }

    /** @return array{0: string, 1: ?string} */
    public function direction(mixed $spec, string $field): array
    {
        $nulls = null;
        if (is_array($spec)) {
            $nulls = isset($spec['nulls']) ? strtolower((string) $spec['nulls']) : null;
            $spec = $spec['sort'] ?? 'asc';
        }
        $dir = strtolower((string) $spec);
        if (! in_array($dir, ['asc', 'desc'], true) || ($nulls !== null && ! in_array($nulls, ['first', 'last'], true))) {
            throw new PrismaClientValidationError(sprintf('Invalid sort order for `%s`: use "asc" or "desc" (nulls: "first" | "last").', $field));
        }

        return [strtoupper($dir), $nulls];
    }

    /** "ORDER BY ..." (or '') with the params of its expressions appended. @param list<array{0: string, 1: string, 2: ?string, 3: list<mixed>}> $orders @param list<mixed> $params */
    public function orderSql(array $orders, array &$params): string
    {
        if ($orders === []) {
            return '';
        }
        $parts = [];
        foreach ($orders as [$expr, $dir, $nulls, $exprParams]) {
            if ($nulls !== null) {
                // portable NULLS FIRST / LAST (MySQL and MariaDB have no such clause)
                $parts[] = "CASE WHEN $expr IS NULL THEN " . ($nulls === 'first' ? '0' : '1') . ' ELSE ' . ($nulls === 'first' ? '1' : '0') . ' END';
                array_push($params, ...$exprParams);
            }
            $parts[] = "$expr $dir";
            array_push($params, ...$exprParams);
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    /** Same order, reversed (negative take). @param list<array{0: string, 1: string, 2: ?string, 3: list<mixed>}> $orders @return list<array{0: string, 1: string, 2: ?string, 3: list<mixed>}> */
    public static function reverse(array $orders): array
    {
        return array_map(fn (array $o) => [$o[0], $o[1] === 'ASC' ? 'DESC' : 'ASC', $o[2] === null ? null : ($o[2] === 'first' ? 'last' : 'first'), $o[3]], $orders);
    }

    /** LIMIT / OFFSET clause. */
    public function limit(?int $take, ?int $skip): string
    {
        $skip = $skip !== null && $skip > 0 ? $skip : null;
        if ($take === null && $skip === null) {
            return '';
        }
        if ($take === null) {
            return match ($this->engine->platform) {
                'pgsql' => " OFFSET $skip",
                'sqlite' => " LIMIT -1 OFFSET $skip",
                default => " LIMIT 18446744073709551615 OFFSET $skip",
            };
        }

        return " LIMIT $take" . ($skip !== null ? " OFFSET $skip" : '');
    }
}
