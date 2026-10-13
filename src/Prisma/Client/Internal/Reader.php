<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Internal;

use Closure;
use Nesto\Prisma\Client\Errors\PrismaClientValidationError;
use Nesto\Prisma\Dmmf\Field;

/**
 * Read queries: findMany / findFirst / findUnique, relation loading for `include` / `select` (one batched
 * query per relation and level, like Prisma's default "query" relation load strategy; per-parent `take` /
 * `skip` with ROW_NUMBER() windows), `_count`, count, aggregate and groupBy.
 *
 * Records are plain PHP arrays keyed by field name — the shape follows select / include / omit, like the
 * plain objects Prisma Client returns.
 *
 * @internal
 */
final class Reader
{
    private const FIND_ARGS = ['where', 'select', 'include', 'omit', 'orderBy', 'cursor', 'take', 'skip', 'distinct', 'relationLoadStrategy'];
    private const IN_CHUNK = 2000;

    /**
     * @param array<string, array<string, array{needs: list<string>, compute: Closure}>> $computed model / '$allModels' => field => definition
     * @param array<string, array<string, bool>> $omit the client's global `omit` option: model (or delegate name) => field => true
     */
    public function __construct(public readonly Engine $engine, private readonly array $computed = [], private readonly array $omit = []) {}

    /** The same reader with other result-extension fields. @param array<string, array<string, array{needs: list<string>, compute: Closure}>> $computed */
    public function withComputed(array $computed): self
    {
        return new self($this->engine, $computed, $this->omit);
    }

    /** The selection of a query (select / include / omit with the global omit and computed fields). @param array<string, mixed> $args */
    public function selection(ModelInfo $model, array $args): Selection
    {
        return Selection::parse($model, $args, $this->computedFor($model), (array) ($this->omit[$model->name] ?? $this->omit[$model->delegateName()] ?? []));
    }

    /** @return array<string, array{needs: list<string>, compute: Closure}> */
    public function computedFor(ModelInfo $model): array
    {
        return ($this->computed['$allModels'] ?? []) + ($this->computed[$model->name] ?? []);
    }

    /** @param array<string, mixed> $args @param list<string> $allowed */
    public static function assertArgs(array $args, array $allowed, string $operation): void
    {
        foreach (array_keys($args) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new PrismaClientValidationError(sprintf('Unknown argument `%s` for `%s()`. Available options: %s.', $key, $operation, implode(', ', $allowed)));
            }
        }
    }

    // ---- find --------------------------------------------------------------------------------------

    /** @param array<string, mixed> $args @return list<array<string, mixed>> */
    public function findMany(ModelInfo $model, array $args): array
    {
        self::assertArgs($args, self::FIND_ARGS, 'findMany');
        $selection = $this->selection($model, $args);
        $fields = $selection->columns($model, $this->computedFor($model));
        $rows = $this->rows($model, $args, $this->withDistinct($model, $fields, $args));

        return $this->hydrate($model, $rows, $selection);
    }

    /** @param array<string, mixed> $args @return array<string, mixed>|null */
    public function findFirst(ModelInfo $model, array $args): ?array
    {
        self::assertArgs($args, self::FIND_ARGS, 'findFirst');
        $args['take'] = isset($args['take']) && (int) $args['take'] < 0 ? -1 : 1;

        return $this->findMany($model, $args)[0] ?? null;
    }

    /** @param array<string, mixed> $args @return array<string, mixed>|null */
    public function findUnique(ModelInfo $model, array $args): ?array
    {
        self::assertArgs($args, ['where', 'select', 'include', 'omit', 'relationLoadStrategy'], 'findUnique');
        $where = $args['where'] ?? null;
        self::assertUnique($model, $where, 'findUnique');
        $args['take'] = 1;

        return $this->findMany($model, $args)[0] ?? null;
    }

    /** `where` must name a unique criterion (WhereUniqueInput). */
    public static function assertUnique(ModelInfo $model, mixed $where, string $operation): void
    {
        if (is_array($where)) {
            foreach ($model->uniqueKeys as $key => $fields) {
                if (! array_key_exists($key, $where)) {
                    continue;
                }
                $value = $where[$key];
                if (count($fields) > 1 ? is_array($value) : ($value !== null && (! is_array($value) || array_key_exists('equals', $value)))) {
                    return;
                }
            }
        }
        throw new PrismaClientValidationError(sprintf(
            'Argument `where` of type %sWhereUniqueInput needs at least one of %s arguments (in `%s()`).',
            $model->name,
            implode(', ', array_map(fn ($k) => '`' . $k . '`', array_keys($model->uniqueKeys))),
            $operation,
        ));
    }

    /** @param list<string> $fields @param array<string, mixed> $args @return list<string> */
    private function withDistinct(ModelInfo $model, array $fields, array $args): array
    {
        foreach ($this->distinctFields($model, $args) as $field) {
            if (! in_array($field, $fields, true)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /** @param array<string, mixed> $args @return list<string> */
    private function distinctFields(ModelInfo $model, array $args): array
    {
        $distinct = $args['distinct'] ?? null;
        if ($distinct === null || $distinct === []) {
            return [];
        }
        $distinct = (array) $distinct;
        foreach ($distinct as $field) {
            if ($model->scalar((string) $field) === null) {
                throw new PrismaClientValidationError(sprintf('Unknown field `%s` in `distinct` of model `%s`.', $field, $model->name));
            }
        }

        return array_values(array_map('strval', $distinct));
    }

    /**
     * Raw rows (field name => database value) for where / orderBy / cursor / take / skip / distinct.
     *
     * $scope narrows the rows to the children of some parents (relation loading):
     * fn (QueryCompiler $c, string $alias) => ['from' => sql, 'where' => sql, 'params' => list, 'columns' => list<sql>, 'partition' => ?sql]
     *
     * @param array<string, mixed> $args
     * @param list<string>         $fields
     * @return list<array<string, mixed>>
     */
    public function rows(ModelInfo $model, array $args, array $fields, ?Closure $scope = null): array
    {
        $c = new QueryCompiler($this->engine);
        $a = $c->alias();
        $scoped = $scope !== null ? $scope($c, $a) : [];
        $from = $scoped['from'] ?? $c->table($model) . " $a";
        $params = $scoped['params'] ?? [];
        $conditions = isset($scoped['where']) ? [$scoped['where']] : [];
        $where = $args['where'] ?? null;
        if ($where !== null && ! is_array($where)) {
            throw new PrismaClientValidationError('Argument `where` must be an object.');
        }
        if ($where !== null && $where !== []) {
            $conditions[] = $c->where($model, $where, $a, $params);
        }
        $orders = $c->orderBy($model, $args['orderBy'] ?? null, $a);
        $take = isset($args['take']) ? (int) $args['take'] : null;
        $skip = isset($args['skip']) ? (int) $args['skip'] : null;
        if ($skip !== null && $skip < 0) {
            throw new PrismaClientValidationError('Argument `skip` must be a non-negative integer.');
        }
        $cursor = $args['cursor'] ?? null;
        $distinct = $this->distinctFields($model, $args);
        $partition = $scoped['partition'] ?? null;
        $reverse = false;
        if ($cursor !== null || ($take !== null && $take < 0) || ($partition !== null && ($take !== null || $skip !== null))) {
            $orders = $this->withTieBreaker($c, $model, $orders, $a);
        }
        if ($take !== null && $take < 0) {
            $orders = QueryCompiler::reverse($orders);
            $take = -$take;
            $reverse = true;
        }
        if ($cursor !== null) {
            if (! is_array($cursor)) {
                throw new PrismaClientValidationError('Argument `cursor` must be a unique where object.');
            }
            $condition = $this->cursorCondition($c, $model, $a, $cursor, $orders, $scoped);
            if ($condition === null) {
                return []; // the cursor record does not exist
            }
            $conditions[] = $condition[0];
            array_push($params, ...$condition[1]);
        }
        $columns = [];
        foreach ($fields as $field) {
            $columns[] = $c->col($a, $model, $field) . ' AS ' . $c->q($field);
        }
        foreach ($scoped['columns'] ?? [] as $column) {
            $columns[] = $column;
        }
        $whereSql = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        if ($partition !== null && ($take !== null || $skip !== null) && $distinct === []) {
            $windowParams = [];
            $window = 'ROW_NUMBER() OVER (PARTITION BY ' . $partition . $c->orderSql($orders, $windowParams) . ')';
            $inner = 'SELECT ' . implode(', ', $columns) . ', ' . $window . ' AS ' . $c->q('__rn') . ' FROM ' . $from . $whereSql;
            $low = $skip ?? 0;
            $filter = $c->q('__rn') . ' > ' . $low . ($take !== null ? ' AND ' . $c->q('__rn') . ' <= ' . ($low + $take) : '');
            $sql = 'SELECT * FROM (' . $inner . ') ' . $c->q('w') . ' WHERE ' . $filter . ' ORDER BY ' . $c->q('__rn');
            $rows = $this->engine->select($sql, [...$windowParams, ...$params]);
        } else {
            $sql = 'SELECT ' . implode(', ', $columns) . ' FROM ' . $from . $whereSql . $c->orderSql($orders, $params);
            if ($distinct === []) {
                $sql .= $c->limit($take, $skip);
            }
            $rows = $this->engine->select($sql, $params);
            if ($distinct !== []) {
                $seen = [];
                $unique = [];
                foreach ($rows as $row) {
                    $key = self::key($row, $distinct);
                    if (! isset($seen[$key])) {
                        $seen[$key] = true;
                        $unique[] = $row;
                    }
                }
                $rows = array_slice($unique, $skip ?? 0, $take);
            }
        }

        return $reverse ? array_reverse($rows) : $rows;
    }

    /**
     * Orders made unique by the model's identity fields (cursors and negative take need a total order).
     *
     * @param list<array{0: string, 1: string, 2: ?string, 3: list<mixed>}> $orders
     * @return list<array{0: string, 1: string, 2: ?string, 3: list<mixed>}>
     */
    private function withTieBreaker(QueryCompiler $c, ModelInfo $model, array $orders, string $a): array
    {
        $present = array_map(fn (array $o) => $o[0], $orders);
        foreach ($model->identity() as $field) {
            $expr = $c->col($a, $model, $field);
            if (! in_array($expr, $present, true)) {
                $orders[] = [$expr, 'ASC', null, []];
            }
        }

        return $orders;
    }

    /**
     * Rows at or after the cursor record in the given order: (o1 > v1) OR (o1 = v1 AND o2 > v2) OR ... OR all equal.
     *
     * @param array<string, mixed> $cursor
     * @param list<array{0: string, 1: string, 2: ?string, 3: list<mixed>}> $orders
     * @param array<string, mixed> $scoped relation scope: the cursor record must be one of the parent's records
     * @return array{0: string, 1: list<mixed>}|null null when the cursor record does not exist
     */
    private function cursorCondition(QueryCompiler $c, ModelInfo $model, string $a, array $cursor, array $orders, array $scoped = []): ?array
    {
        $selectParams = [];
        $selects = [];
        foreach ($orders as $i => [$expr, , , $exprParams]) {
            $selects[] = $expr . ' AS ' . $c->q('c' . $i);
            array_push($selectParams, ...$exprParams);
        }
        $whereParams = (array) ($scoped['params'] ?? []);
        $condition = $c->where($model, $cursor, $a, $whereParams);
        if (isset($scoped['where'])) {
            $condition = $scoped['where'] . ' AND ' . $condition;
        }
        $from = $scoped['from'] ?? $c->table($model) . " $a";
        $row = $this->engine->select('SELECT ' . implode(', ', $selects) . ' FROM ' . $from . ' WHERE ' . $condition . $c->limit(1, null), [...$selectParams, ...$whereParams])[0] ?? null;
        if ($row === null) {
            return null;
        }
        $values = array_values($row);
        $params = [];
        $disjuncts = [];
        $count = count($orders);
        for ($i = 0; $i <= $count; $i++) {
            $conjuncts = [];
            for ($j = 0; $j < min($i, $count); $j++) {
                $conjuncts[] = $this->equals($orders[$j], $values[$j], $params);
            }
            if ($i < $count) {
                [$expr, $dir, , $exprParams] = $orders[$i];
                if ($values[$i] === null) {
                    continue;
                }
                array_push($params, ...$exprParams);
                $params[] = $values[$i];
                $conjuncts[] = $expr . ($dir === 'ASC' ? ' > ?' : ' < ?');
            }
            $disjuncts[] = '(' . implode(' AND ', $conjuncts) . ')';
        }

        return ['(' . implode(' OR ', $disjuncts) . ')', $params];
    }

    /** @param array{0: string, 1: string, 2: ?string, 3: list<mixed>} $order @param list<mixed> $params */
    private function equals(array $order, mixed $value, array &$params): string
    {
        array_push($params, ...$order[3]);
        if ($value === null) {
            return $order[0] . ' IS NULL';
        }
        $params[] = $value;

        return $order[0] . ' = ?';
    }

    /** @param array<string, mixed> $row @param list<string> $fields */
    public static function key(array $row, array $fields): string
    {
        $parts = [];
        foreach ($fields as $field) {
            $value = $row[$field] ?? null;
            if (is_resource($value)) {
                $value = stream_get_contents($value);
            }
            $parts[] = match (true) {
                $value === null => "\0",
                is_bool($value) => $value ? '1' : '0',
                is_float($value) => rtrim(rtrim(sprintf('%.12F', $value), '0'), '.'),
                default => (string) $value,
            };
        }

        return implode("\x1F", $parts);
    }

    // ---- hydration -------------------------------------------------------------------------------

    /**
     * Records for raw rows, in the same order: decoded scalars, the selected relations (loaded in one query per
     * relation), `_count`, computed fields.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function hydrate(ModelInfo $model, array $rows, Selection $selection): array
    {
        if ($rows === []) {
            return [];
        }
        $relations = [];
        foreach ($selection->relations as $name => $args) {
            $relations[$name] = $this->loadRelation($model, $model->relations[$name], $rows, $args);
        }
        $counts = $selection->count !== null ? $this->loadCounts($model, $rows, $selection->count) : null;
        $computed = $selection->computed !== [] ? $this->computedFor($model) : [];
        $codec = $this->engine->codec;
        $records = [];
        foreach ($rows as $i => $row) {
            $record = [];
            foreach ($selection->keys as $key) {
                if (isset($model->scalars[$key])) {
                    $record[$key] = $codec->decode($model->scalars[$key], $row[$key] ?? null);
                } elseif (isset($relations[$key])) {
                    $record[$key] = $relations[$key][$i];
                } elseif ($key === '_count' && $counts !== null) {
                    $record['_count'] = $counts[$i];
                } else {
                    $record[$key] = null; // computed below
                }
            }
            if ($computed !== []) {
                $input = $record;
                foreach ($computed as $name => $definition) {
                    if (! in_array($name, $selection->computed, true)) {
                        continue;
                    }
                    foreach ($definition['needs'] as $need) {
                        if (! array_key_exists($need, $input) && ($field = $model->scalar($need)) !== null) {
                            $input[$need] = $codec->decode($field, $row[$need] ?? null);
                        }
                    }
                }
                foreach ($selection->computed as $name) {
                    $record[$name] = ($computed[$name]['compute'])($input);
                }
            }
            $records[] = $record;
        }

        return $records;
    }

    /**
     * The related records of every parent row: list<record> for list relations, record|null for to-one.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $args nested select / include / omit / where / orderBy / take / skip / cursor / distinct
     * @return array<int, mixed> by row index
     */
    private function loadRelation(ModelInfo $model, RelationInfo $relation, array $rows, array $args): array
    {
        $target = $this->engine->model($relation->target);
        $selection = $this->selection($target, $args);
        $thisFields = $relation->thisFields();
        $keys = [];
        foreach ($rows as $row) {
            if (self::hasNull($row, $thisFields)) {
                continue;
            }
            $keys[self::key($row, $thisFields)] = array_map(fn (string $f) => $row[$f], $thisFields);
        }
        $empty = $relation->isList() ? [] : null;
        if ($keys === []) {
            return array_fill(0, count($rows), $empty);
        }
        $fields = $selection->columns($target, $this->computedFor($target));
        $isM2m = $relation->kind === RelationInfo::M2M;
        $groupFields = $isM2m ? ['__p'] : $relation->otherFields();
        if (! $isM2m) {
            foreach ($groupFields as $field) {
                if (! in_array($field, $fields, true)) {
                    $fields[] = $field;
                }
            }
        }
        $queryArgs = array_intersect_key($args, array_flip(['where', 'orderBy', 'take', 'skip', 'cursor', 'distinct']));
        $perParent = isset($queryArgs['cursor']) || (isset($queryArgs['distinct']) && (isset($queryArgs['take']) || isset($queryArgs['skip'])));
        $fields = $this->withDistinct($target, $fields, $queryArgs);
        $childRows = [];
        $chunks = $perParent ? array_map(fn ($k) => [$k], array_values($keys)) : array_chunk(array_values($keys), self::IN_CHUNK);
        foreach ($chunks as $chunk) {
            $scope = function (QueryCompiler $c, string $a) use ($model, $relation, $target, $chunk, $isM2m, $perParent): array {
                $params = [];
                if ($isM2m) {
                    $j = $c->alias();
                    $parentColumn = "$j." . $c->q((string) $relation->thisColumn);
                    $from = $c->q((string) $relation->joinTable) . " $j INNER JOIN " . $c->table($target) . " $a ON $a." . $c->q($target->column($relation->pairs[0][1])) . " = $j." . $c->q((string) $relation->otherColumn);
                    $where = $this->inTuples([$parentColumn], $chunk, $params);

                    return ['from' => $from, 'where' => $where, 'params' => $params, 'columns' => [$parentColumn . ' AS ' . $c->q('__p')], 'partition' => $perParent ? null : $parentColumn];
                }
                $columns = array_map(fn (string $f) => $c->col($a, $target, $f), $relation->otherFields());

                return ['where' => $this->inTuples($columns, $chunk, $params), 'params' => $params, 'partition' => $perParent ? null : implode(', ', $columns)];
            };
            foreach ($this->rows($target, $queryArgs, $fields, $scope) as $childRow) {
                $childRows[] = $childRow;
            }
        }
        $records = $this->hydrate($target, $childRows, $selection);
        $grouped = [];
        foreach ($childRows as $i => $childRow) {
            $grouped[self::key($childRow, $groupFields)][] = $records[$i];
        }
        $out = [];
        foreach ($rows as $i => $row) {
            $group = self::hasNull($row, $thisFields) ? [] : ($grouped[self::key($row, $thisFields)] ?? []);
            $out[$i] = $relation->isList() ? $group : ($group[0] ?? null);
        }

        return $out;
    }

    /**
     * `(a = ? AND b = ?) OR ...`, or `col IN (?, ...)` for one column.
     *
     * @param list<string>      $columns SQL expressions
     * @param list<list<mixed>> $tuples
     * @param list<mixed>       $params
     */
    public function inTuples(array $columns, array $tuples, array &$params): string
    {
        if ($tuples === []) {
            return '1=0';
        }
        if (count($columns) === 1) {
            foreach ($tuples as $tuple) {
                $params[] = $tuple[0];
            }

            return $columns[0] . ' IN (' . implode(', ', array_fill(0, count($tuples), '?')) . ')';
        }
        $parts = [];
        foreach ($tuples as $tuple) {
            $conjuncts = [];
            foreach ($columns as $k => $column) {
                $params[] = $tuple[$k];
                $conjuncts[] = "$column = ?";
            }
            $parts[] = '(' . implode(' AND ', $conjuncts) . ')';
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    /** @param array<string, mixed> $row @param list<string> $fields */
    private static function hasNull(array $row, array $fields): bool
    {
        foreach ($fields as $field) {
            if (($row[$field] ?? null) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * `_count` of list relations per row.
     *
     * @param list<array<string, mixed>>          $rows
     * @param array<string, array<string, mixed>> $count relation => where
     * @return array<int, array<string, int>>
     */
    private function loadCounts(ModelInfo $model, array $rows, array $count): array
    {
        $out = array_fill(0, count($rows), []);
        foreach ($count as $name => $where) {
            $relation = $model->relations[$name];
            $target = $this->engine->model($relation->target);
            $thisFields = $relation->thisFields();
            $keys = [];
            foreach ($rows as $row) {
                if (! self::hasNull($row, $thisFields)) {
                    $keys[self::key($row, $thisFields)] = array_map(fn (string $f) => $row[$f], $thisFields);
                }
            }
            $counts = [];
            foreach (array_chunk(array_values($keys), self::IN_CHUNK) as $chunk) {
                $c = new QueryCompiler($this->engine);
                $a = $c->alias();
                $params = [];
                if ($relation->kind === RelationInfo::M2M) {
                    $j = $c->alias();
                    $parent = "$j." . $c->q((string) $relation->thisColumn);
                    $groupColumns = [$parent];
                    $from = $c->q((string) $relation->joinTable) . " $j INNER JOIN " . $c->table($target) . " $a ON $a." . $c->q($target->column($relation->pairs[0][1])) . " = $j." . $c->q((string) $relation->otherColumn);
                } else {
                    $groupColumns = array_map(fn (string $f) => $c->col($a, $target, $f), $relation->otherFields());
                    $from = $c->table($target) . " $a";
                }
                $condition = $this->inTuples($groupColumns, $chunk, $params);
                if ($where !== []) {
                    $condition .= ' AND ' . $c->where($target, $where, $a, $params);
                }
                $selects = [];
                foreach ($groupColumns as $k => $column) {
                    $selects[] = $column . ' AS ' . $c->q('k' . $k);
                }
                $sql = 'SELECT ' . implode(', ', $selects) . ', COUNT(*) AS ' . $c->q('n') . ' FROM ' . $from . ' WHERE ' . $condition . ' GROUP BY ' . implode(', ', $groupColumns);
                foreach ($this->engine->select($sql, $params) as $result) {
                    $counts[self::key($result, array_map(fn ($k) => 'k' . $k, array_keys($groupColumns)))] = (int) $result['n'];
                }
            }
            foreach ($rows as $i => $row) {
                $out[$i][$name] = self::hasNull($row, $thisFields) ? 0 : ($counts[self::key($row, $thisFields)] ?? 0);
            }
        }

        return $out;
    }

    // ---- count / aggregate / groupBy -------------------------------------------------------------

    /**
     * `count()`: an int, or with `select` (`{ _all: true, name: true }`) the non-null count per field.
     *
     * @param array<string, mixed> $args
     * @return int|array<string, int>
     */
    public function count(ModelInfo $model, array $args): int|array
    {
        self::assertArgs($args, ['where', 'orderBy', 'cursor', 'take', 'skip', 'distinct', 'select'], 'count');
        $select = $args['select'] ?? null;
        unset($args['select']);
        $fields = [];
        if (is_array($select)) {
            foreach ($select as $key => $on) {
                if ($on && $key !== '_all' && $model->scalar((string) $key) === null) {
                    throw new PrismaClientValidationError(sprintf('Unknown field `%s` in count select of model `%s`.', $key, $model->name));
                }
                if ($on) {
                    $fields[] = (string) $key;
                }
            }
        }
        $result = $this->aggregateRows($model, $args, ['_count' => array_fill_keys($fields === [] ? ['_all'] : $fields, true)]);
        $counts = $result['_count'];

        return is_array($select) ? $counts : (int) $counts['_all'];
    }

    /**
     * `aggregate()`: `_count`, `_avg`, `_sum`, `_min`, `_max` over the rows matched by where / orderBy / cursor /
     * take / skip.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function aggregate(ModelInfo $model, array $args): array
    {
        self::assertArgs($args, ['where', 'orderBy', 'cursor', 'take', 'skip', 'distinct', '_count', '_avg', '_sum', '_min', '_max'], 'aggregate');
        $aggregates = array_intersect_key($args, array_flip(['_count', '_avg', '_sum', '_min', '_max']));
        $rowArgs = array_diff_key($args, $aggregates);
        $countAll = ($aggregates['_count'] ?? null) === true;
        if ($countAll) {
            $aggregates['_count'] = ['_all' => true];
        }
        $result = $this->aggregateRows($model, $rowArgs, $aggregates);
        if ($countAll) {
            $result['_count'] = $result['_count']['_all'];
        }

        return $result;
    }

    /**
     * @param array<string, mixed>                $rowArgs
     * @param array<string, array<string, mixed>> $aggregates
     * @return array<string, mixed>
     */
    private function aggregateRows(ModelInfo $model, array $rowArgs, array $aggregates): array
    {
        $plan = $this->aggregatePlan($model, $aggregates);
        $needed = [];
        foreach ($plan as [, , $field]) {
            if ($field !== null) {
                $needed[$field] = true;
            }
        }
        $fields = array_keys($needed);
        $hasWindow = isset($rowArgs['take']) || isset($rowArgs['skip']) || isset($rowArgs['cursor']) || isset($rowArgs['distinct']);
        $c = new QueryCompiler($this->engine);
        if (! $hasWindow) {
            $a = $c->alias();
            $params = [];
            $where = isset($rowArgs['where']) && $rowArgs['where'] !== [] ? ' WHERE ' . $c->where($model, $rowArgs['where'], $a, $params) : '';
            $selects = $this->aggregateSelects($c, $model, $plan, $a);
            $row = $this->engine->select('SELECT ' . implode(', ', $selects) . ' FROM ' . $c->table($model) . " $a" . $where, $params)[0] ?? [];
        } else {
            // aggregate over the selected window of rows
            $rows = $this->rows($model, $rowArgs, $fields === [] ? $model->identity() : $fields);
            $row = $this->aggregateInPhp($model, $plan, $rows);
        }

        return $this->aggregateResult($model, $plan, $row);
    }

    /**
     * @param array<string, array<string, mixed>|bool> $aggregates
     * @return list<array{0: string, 1: string, 2: ?string}> [function, output key, field|null]
     */
    private function aggregatePlan(ModelInfo $model, array $aggregates): array
    {
        $plan = [];
        foreach ($aggregates as $function => $selection) {
            if (! is_array($selection)) {
                throw new PrismaClientValidationError(sprintf('Argument `%s` must be an object of fields (`{ field: true }`).', $function));
            }
            foreach ($selection as $field => $on) {
                if (! $on) {
                    continue;
                }
                $field = (string) $field;
                if ($field === '_all' && $function === '_count') {
                    $plan[] = [$function, $field, null];
                    continue;
                }
                $info = $model->scalar($field);
                if ($info === null) {
                    throw new PrismaClientValidationError(sprintf('Unknown field `%s` in `%s` of model `%s`.', $field, $function, $model->name));
                }
                if (in_array($function, ['_avg', '_sum'], true) && ! in_array($info->type, ['Int', 'BigInt', 'Float', 'Decimal'], true)) {
                    throw new PrismaClientValidationError(sprintf('`%s` is only available for numeric fields, `%s` is %s.', $function, $field, $info->type));
                }
                $plan[] = [$function, $field, $field];
            }
        }

        return $plan;
    }

    /**
     * The SELECT list of an aggregate plan: `a<k>` per aggregate. A Decimal `_avg` is selected as its SUM and
     * COUNT (`a<k>n`) and divided in PHP, so every database returns the same digits (Prisma's Decimal result).
     *
     * @param list<array{0: string, 1: string, 2: ?string}> $plan
     * @return list<string>
     */
    private function aggregateSelects(QueryCompiler $c, ModelInfo $model, array $plan, string $a): array
    {
        $selects = [];
        foreach ($plan as $k => [$function, , $field]) {
            $column = $field === null ? null : $c->col($a, $model, $field);
            if ($function === '_avg' && $column !== null && $model->scalars[(string) $field]->type === 'Decimal') {
                $selects[] = "SUM($column) AS " . $c->q('a' . $k);
                $selects[] = "COUNT($column) AS " . $c->q('a' . $k . 'n');
                continue;
            }
            $selects[] = $this->aggregateSql($function, $column, $model, $field) . ' AS ' . $c->q('a' . $k);
        }

        return $selects;
    }

    private function aggregateSql(string $function, ?string $column, ModelInfo $model, ?string $field): string
    {
        if ($column === null) {
            return 'COUNT(*)';
        }
        $type = $field !== null ? $model->scalars[$field]->type : null;
        // Int / BigInt / Float averages are floats (Prisma's Float result): MySQL / MariaDB's AVG of integers is a
        // DECIMAL with 4 digits, so average DOUBLEs there; PostgreSQL's numeric AVG keeps 16+ digits
        $average = match (true) {
            $type === 'Decimal', $this->engine->platform === 'pgsql' => $column,
            $this->engine->platform === 'sqlite' => $column . ' * 1.0',
            default => $column . ' * 1e0',
        };

        return match ($function) {
            '_count' => "COUNT($column)",
            '_avg' => "AVG($average)",
            '_sum' => "SUM($column)",
            '_min' => "MIN($column)",
            '_max' => "MAX($column)",
        };
    }

    /**
     * @param list<array{0: string, 1: string, 2: ?string}> $plan
     * @param list<array<string, mixed>>                   $rows
     * @return array<string, mixed>
     */
    private function aggregateInPhp(ModelInfo $model, array $plan, array $rows): array
    {
        $out = [];
        foreach ($plan as $k => [$function, , $field]) {
            $values = $field === null ? $rows : array_values(array_filter(array_map(fn ($r) => $r[$field] ?? null, $rows), fn ($v) => $v !== null));
            $type = $field !== null ? $model->scalars[$field]->type : null;
            $isDecimal = $type === 'Decimal';
            $out['a' . $k] = match ($function) {
                '_count' => count($values),
                '_sum' => $values === [] ? null : ($isDecimal ? self::decimalSum($values) : array_sum(array_map(fn ($v) => $type === 'Float' ? (float) $v : (int) $v, $values))),
                '_avg' => $values === [] ? null : ($isDecimal ? self::decimalSum($values) : array_sum(array_map('floatval', $values)) / count($values)),
                '_min' => $values === [] ? null : $this->extreme($model->scalars[(string) $field], $values, false),
                '_max' => $values === [] ? null : $this->extreme($model->scalars[(string) $field], $values, true),
            };
            if ($function === '_avg' && $isDecimal) {
                $out['a' . $k . 'n'] = count($values); // divided by aggregateValue(), like the SQL path
            }
        }

        return $out;
    }

    /** @param list<mixed> $values */
    private function extreme(Field $field, array $values, bool $max): mixed
    {
        $codec = $this->engine->codec;
        $best = null;
        $bestDecoded = null;
        foreach ($values as $value) {
            $decoded = $codec->decode($field, $value);
            $comparable = $decoded instanceof \DateTimeInterface ? $decoded->format('U.u') : ($decoded instanceof \BackedEnum ? $decoded->value : $decoded);
            if ($best === null || ($max ? $comparable > $bestDecoded : $comparable < $bestDecoded)) {
                $best = $value;
                $bestDecoded = $comparable;
            }
        }

        return $best;
    }

    /** @param list<mixed> $values */
    private static function decimalSum(array $values): string
    {
        if (function_exists('bcadd')) {
            $sum = '0';
            foreach ($values as $value) {
                $sum = bcadd($sum, ValueCodec::normalizeDecimal((string) $value), 30);
            }

            return ValueCodec::normalizeDecimal($sum);
        }

        return ValueCodec::normalizeDecimal((string) array_sum(array_map('floatval', $values)));
    }

    /**
     * A Decimal average like PostgreSQL computes it (Prisma's reference): $sum / $count rounded half away from zero
     * to max(16 significant digits, the column's scale) — DECIMAL(65,30), Prisma's default, gives 30 digits.
     */
    private static function decimalAverage(string $sum, int $count, int $scale): string
    {
        $sum = ValueCodec::normalizeDecimal($sum);
        $negative = str_starts_with($sum, '-');
        [$sumWeight, $sumFirst] = self::numericWeight(ltrim($sum, '-'));
        [$countWeight, $countFirst] = self::numericWeight((string) $count);
        $quotientWeight = $sumWeight - $countWeight - ($sumFirst <= $countFirst ? 1 : 0);
        $rscale = min(max(16 - $quotientWeight * 4, $scale, 0), 1000);
        if (! function_exists('bcdiv')) {
            $digits = min($rscale, 15);

            return ValueCodec::normalizeDecimal(number_format(round((float) $sum / $count, $digits), $digits, '.', ''));
        }
        $quotient = bcdiv($sum, (string) $count, $rscale + 1);
        $last = (int) substr($quotient, -1);
        $quotient = substr($quotient, 0, -1);
        if ($last >= 5) {
            $unit = $rscale === 0 ? '1' : '0.' . str_repeat('0', $rscale - 1) . '1';
            $quotient = bcadd($quotient, ($negative ? '-' : '') . $unit, $rscale);
        }

        return ValueCodec::normalizeDecimal(rtrim($quotient, '.'));
    }

    /**
     * PostgreSQL's numeric weight (position of the first non-zero base-10000 digit) and that digit, of a
     * non-negative decimal string.
     *
     * @return array{0: int, 1: int}
     */
    private static function numericWeight(string $value): array
    {
        [$integer, $fraction] = explode('.', $value . '.', 3);
        $integer = ltrim($integer, '0');
        if ($integer !== '') {
            $length = strlen($integer);

            return [intdiv($length - 1, 4), (int) substr($integer, 0, ($length - 1) % 4 + 1)];
        }
        $fraction = rtrim($fraction, '0');
        if ($fraction === '') {
            return [0, 0];
        }
        foreach (str_split(str_pad($fraction, (int) ceil(strlen($fraction) / 4) * 4, '0'), 4) as $i => $group) {
            if ((int) $group !== 0) {
                return [-($i + 1), (int) $group];
            }
        }

        return [0, 0];
    }

    /** Scale of a Decimal column: @db.Decimal(p, s) / Money, else Prisma's default DECIMAL(65,30). */
    private static function decimalScale(Field $field, string $sum): int
    {
        $native = $field->nativeType;
        if ($native === null) {
            return 30;
        }
        if (isset($native[1][1])) {
            return (int) $native[1][1];
        }
        if (strtolower($native[0]) === 'money') {
            return 2;
        }
        // unconstrained NUMERIC: the scale of the values
        $dot = strpos($sum, '.');

        return $dot === false ? 0 : strlen($sum) - $dot - 1;
    }

    /**
     * @param list<array{0: string, 1: string, 2: ?string}> $plan
     * @param array<string, mixed>                         $row
     * @return array<string, mixed>
     */
    private function aggregateResult(ModelInfo $model, array $plan, array $row): array
    {
        $result = [];
        foreach ($plan as $k => [$function, $key, $field]) {
            $result[$function][$key] = $this->aggregateValue($model, $function, $field, $row['a' . $k] ?? null, $row['a' . $k . 'n'] ?? null);
        }

        return $result;
    }

    /**
     * An aggregate in Prisma's type: `_count` Int; `_avg` Float (Decimal for Decimal fields, $count is then the
     * COUNT that $value — the SUM — is divided by); `_sum` the field's type; `_min` / `_max` decoded like the field.
     */
    private function aggregateValue(ModelInfo $model, string $function, ?string $field, mixed $value, mixed $count = null): mixed
    {
        if ($function === '_count') {
            return (int) $value;
        }
        if ($value === null) {
            return null;
        }
        $info = $model->scalars[(string) $field];
        if ($function === '_avg') {
            if ($info->type !== 'Decimal') {
                return (float) $value;
            }
            if ($count === null) {
                return ValueCodec::normalizeDecimal((string) $value);
            }
            $sum = is_float($value) ? ValueCodec::normalizeDecimal(sprintf('%.15F', $value)) : (string) $value;

            return (int) $count === 0 ? null : self::decimalAverage($sum, (int) $count, self::decimalScale($info, $sum));
        }
        if ($function === '_sum') {
            return match ($info->type) {
                'Int' => (int) $value,
                'BigInt' => (string) (int) $value === (string) $value || is_int($value) ? (int) $value : (string) $value,
                'Float' => (float) $value,
                default => ValueCodec::normalizeDecimal((string) $value),
            };
        }

        return $this->engine->codec->decode($info, $value);
    }

    /**
     * `groupBy()`: rows grouped by `by` with aggregates, `having`, `orderBy` (by fields or aggregates), take / skip.
     *
     * @param array<string, mixed> $args
     * @return list<array<string, mixed>>
     */
    public function groupBy(ModelInfo $model, array $args): array
    {
        self::assertArgs($args, ['by', 'where', 'having', 'orderBy', 'take', 'skip', '_count', '_avg', '_sum', '_min', '_max'], 'groupBy');
        $by = array_values(array_map('strval', (array) ($args['by'] ?? [])));
        if ($by === []) {
            throw new PrismaClientValidationError('Argument `by` is missing in `groupBy()`.');
        }
        foreach ($by as $field) {
            if ($model->scalar($field) === null) {
                throw new PrismaClientValidationError(sprintf('Unknown field `%s` in `by` of model `%s`.', $field, $model->name));
            }
        }
        $aggregates = array_intersect_key($args, array_flip(['_count', '_avg', '_sum', '_min', '_max']));
        $countAll = ($aggregates['_count'] ?? null) === true;
        if ($countAll) {
            $aggregates['_count'] = ['_all' => true];
        }
        $plan = $this->aggregatePlan($model, $aggregates);
        $c = new QueryCompiler($this->engine);
        $a = $c->alias();
        $params = [];
        $selects = [];
        $groupColumns = [];
        foreach ($by as $field) {
            $groupColumns[] = $c->col($a, $model, $field);
            $selects[] = $c->col($a, $model, $field) . ' AS ' . $c->q($field);
        }
        array_push($selects, ...$this->aggregateSelects($c, $model, $plan, $a));
        $sql = 'SELECT ' . implode(', ', $selects) . ' FROM ' . $c->table($model) . " $a";
        if (isset($args['where']) && $args['where'] !== []) {
            $sql .= ' WHERE ' . $c->where($model, $args['where'], $a, $params);
        }
        $sql .= ' GROUP BY ' . implode(', ', $groupColumns);
        if (isset($args['having']) && $args['having'] !== []) {
            $sql .= ' HAVING ' . $this->having($c, $model, (array) $args['having'], $a, $params);
        }
        $orders = $this->groupOrders($c, $model, $args['orderBy'] ?? null, $a, $by);
        $sql .= $c->orderSql($orders, $params);
        $sql .= $c->limit(isset($args['take']) ? (int) $args['take'] : null, isset($args['skip']) ? (int) $args['skip'] : null);
        $out = [];
        foreach ($this->engine->select($sql, $params) as $row) {
            $record = [];
            foreach ($by as $field) {
                $record[$field] = $this->engine->codec->decode($model->scalars[$field], $row[$field] ?? null);
            }
            foreach ($this->aggregateResult($model, $plan, $row) as $function => $values) {
                $record[$function] = $function === '_count' && $countAll ? $values['_all'] : $values;
            }
            $out[] = $record;
        }

        return $out;
    }

    /**
     * having: scalar filters on `by` fields, aggregate filters `{ views: { _avg: { gt: 10 } } }`, `_count` /
     * `_avg` ... at the top (`{ _count: { id: { gt: 1 } } }`), AND / OR / NOT.
     *
     * @param array<string, mixed> $having
     * @param list<mixed>          $params
     */
    private function having(QueryCompiler $c, ModelInfo $model, array $having, string $a, array &$params): string
    {
        $parts = [];
        foreach ($having as $key => $value) {
            $key = (string) $key;
            if (in_array($key, ['AND', 'OR', 'NOT'], true)) {
                $items = is_array($value) && array_is_list($value) ? $value : [$value];
                $compiled = [];
                foreach ($items as $h) {
                    $compiled[] = $this->having($c, $model, (array) $h, $a, $params);
                }
                $parts[] = match ($key) {
                    'AND' => $compiled === [] ? '1=1' : '(' . implode(' AND ', $compiled) . ')',
                    'OR' => $compiled === [] ? '1=0' : '(' . implode(' OR ', $compiled) . ')',
                    'NOT' => $compiled === [] ? '1=1' : '(' . implode(' AND ', array_map(fn ($s) => "NOT ($s)", $compiled)) . ')',
                };
                continue;
            }
            if (in_array($key, ['_count', '_avg', '_sum', '_min', '_max'], true)) {
                foreach ((array) $value as $field => $filter) {
                    $parts[] = $this->aggregateFilter($c, $model, $key, (string) $field, $filter, $a, $params);
                }
                continue;
            }
            $field = $model->scalar($key) ?? throw new PrismaClientValidationError(sprintf('Unknown field `%s` in `having` of model `%s`.', $key, $model->name));
            if (is_array($value) && array_intersect(array_keys($value), ['_count', '_avg', '_sum', '_min', '_max']) !== []) {
                foreach ($value as $function => $filter) {
                    if (in_array($function, ['_count', '_avg', '_sum', '_min', '_max'], true)) {
                        $parts[] = $this->aggregateFilter($c, $model, (string) $function, $key, $filter, $a, $params);
                    } else {
                        $parts[] = $c->fieldFilter($field, $c->col($a, $model, $key), [$function => $filter], $params);
                    }
                }
                continue;
            }
            $parts[] = $c->fieldFilter($field, $c->col($a, $model, $key), $value, $params);
        }

        return $parts === [] ? '1=1' : implode(' AND ', $parts);
    }

    /** @param list<mixed> $params */
    private function aggregateFilter(QueryCompiler $c, ModelInfo $model, string $function, string $field, mixed $filter, string $a, array &$params): string
    {
        $info = $field === '_all' ? null : ($model->scalar($field) ?? throw new PrismaClientValidationError(sprintf('Unknown field `%s` in `having`.', $field)));
        $expr = $this->aggregateSql($function, $info === null ? null : $c->col($a, $model, $field), $model, $info?->name);
        $type = match ($function) {
            '_count' => new Field('_count', Field::SCALAR, 'Int'),
            '_avg' => new Field('_avg', Field::SCALAR, $info?->type === 'Decimal' ? 'Decimal' : 'Float'),
            default => $info ?? new Field('_count', Field::SCALAR, 'Int'),
        };

        return $c->fieldFilter($type, $expr, $filter, $params);
    }

    /**
     * @param list<string> $by
     * @return list<array{0: string, 1: string, 2: ?string, 3: list<mixed>}>
     */
    private function groupOrders(QueryCompiler $c, ModelInfo $model, mixed $orderBy, string $a, array $by): array
    {
        if ($orderBy === null || $orderBy === []) {
            return [];
        }
        $items = is_array($orderBy) && array_is_list($orderBy) ? $orderBy : [$orderBy];
        $orders = [];
        foreach ($items as $item) {
            foreach ((array) $item as $key => $spec) {
                $key = (string) $key;
                if (in_array($key, ['_count', '_avg', '_sum', '_min', '_max'], true)) {
                    foreach ((array) $spec as $field => $direction) {
                        $field = (string) $field;
                        $column = $field === '_all' ? null : $c->col($a, $model, $field);
                        [$dir, $nulls] = $c->direction($direction, $field);
                        $orders[] = [$this->aggregateSql($key, $column, $model, $field === '_all' ? null : $field), $dir, $nulls, []];
                    }
                    continue;
                }
                if (! in_array($key, $by, true)) {
                    throw new PrismaClientValidationError(sprintf('Every field used for orderBy must be included in the by-arguments of the query: `%s` is not.', $key));
                }
                [$dir, $nulls] = $c->direction($spec, $key);
                $orders[] = [$c->col($a, $model, $key), $dir, $nulls, []];
            }
        }

        return $orders;
    }
}
