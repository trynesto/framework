<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Internal;

use Nesto\Prisma\Client\Errors\PrismaClientKnownRequestError;
use Nesto\Prisma\Client\Errors\PrismaClientValidationError;
use Nesto\Prisma\Dmmf\Field;
use Nesto\Prisma\Dmmf\FieldDefault;

/**
 * Write queries: create / createMany / createManyAndReturn / update / updateMany / updateManyAndReturn /
 * upsert / delete / deleteMany, nested writes (create, createMany, connect, connectOrCreate, disconnect, set,
 * update, updateMany, upsert, delete, deleteMany) on every relation kind, atomic number operations,
 * client-side defaults (uuid(), cuid(), nanoid(), ulid(), now(), @updatedAt) and — with
 * `relationMode = "prisma"` — emulated onDelete referential actions. Every top-level write runs in one
 * transaction (a savepoint inside an interactive transaction).
 *
 * Rows are identified by their identity (primary key, else the first unique criterion) as decoded PHP values.
 *
 * @internal
 */
final class Writer
{
    private const NESTED_OPS = ['create', 'createMany', 'connect', 'connectOrCreate', 'disconnect', 'set', 'update', 'updateMany', 'upsert', 'delete', 'deleteMany'];
    private const CHUNK = 1000;

    private readonly Engine $engine;

    public function __construct(private readonly Reader $reader)
    {
        $this->engine = $reader->engine;
    }

    // ---- top-level operations --------------------------------------------------------------------

    /** @param array<string, mixed> $args @return array<string, mixed> */
    public function create(ModelInfo $model, array $args): array
    {
        Reader::assertArgs($args, ['data', 'select', 'include', 'omit'], 'create');
        $this->assertWritable($model);
        $this->assertSelection($model, $args);

        return $this->engine->transaction(function () use ($model, $args) {
            $identity = $this->insert($model, $this->dataArg($args, 'create'));

            return $this->readBack($model, $identity, $args);
        });
    }

    /** @param array<string, mixed> $args */
    public function createMany(ModelInfo $model, array $args): int
    {
        Reader::assertArgs($args, ['data', 'skipDuplicates'], 'createMany');
        $this->assertWritable($model);
        $this->assertSelection($model, $args);
        $rows = $this->listArg($args['data'] ?? null, 'data');

        return $this->engine->transaction(fn () => $this->insertMany($model, $rows, (bool) ($args['skipDuplicates'] ?? false))[0]);
    }

    /** @param array<string, mixed> $args @return list<array<string, mixed>> */
    public function createManyAndReturn(ModelInfo $model, array $args): array
    {
        Reader::assertArgs($args, ['data', 'skipDuplicates', 'select', 'include', 'omit'], 'createManyAndReturn');
        $this->assertWritable($model);
        $this->assertSelection($model, $args);
        if (! in_array($this->engine->platform, ['pgsql', 'sqlite'], true)) {
            throw new PrismaClientValidationError('`createManyAndReturn()` is only available for PostgreSQL and SQLite (like in Prisma).');
        }
        $rows = $this->listArg($args['data'] ?? null, 'data');

        return $this->engine->transaction(function () use ($model, $rows, $args) {
            [, $identities] = $this->insertMany($model, $rows, (bool) ($args['skipDuplicates'] ?? false), true);

            return $this->readMany($model, $identities, $args);
        });
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    public function update(ModelInfo $model, array $args): array
    {
        Reader::assertArgs($args, ['where', 'data', 'select', 'include', 'omit'], 'update');
        $this->assertWritable($model);
        $this->assertSelection($model, $args);
        Reader::assertUnique($model, $args['where'] ?? null, 'update');

        return $this->engine->transaction(function () use ($model, $args) {
            $row = $this->findRow($model, $args['where']) ?? throw $this->notFound($model, 'Record to update not found.');
            $identity = $this->applyUpdate($model, $row, $this->dataArg($args, 'update'));

            return $this->readBack($model, $identity, $args);
        });
    }

    /** @param array<string, mixed> $args */
    public function updateMany(ModelInfo $model, array $args): int
    {
        Reader::assertArgs($args, ['where', 'data', 'limit'], 'updateMany');
        $this->assertWritable($model);
        $this->assertSelection($model, $args);

        return $this->engine->transaction(fn () => count($this->updateRows($model, $args['where'] ?? [], $this->dataArg($args, 'updateMany'), isset($args['limit']) ? (int) $args['limit'] : null)));
    }

    /** @param array<string, mixed> $args @return list<array<string, mixed>> */
    public function updateManyAndReturn(ModelInfo $model, array $args): array
    {
        Reader::assertArgs($args, ['where', 'data', 'limit', 'select', 'include', 'omit'], 'updateManyAndReturn');
        $this->assertWritable($model);
        $this->assertSelection($model, $args);
        if (! in_array($this->engine->platform, ['pgsql', 'sqlite'], true)) {
            throw new PrismaClientValidationError('`updateManyAndReturn()` is only available for PostgreSQL and SQLite (like in Prisma).');
        }

        return $this->engine->transaction(function () use ($model, $args) {
            $identities = $this->updateRows($model, $args['where'] ?? [], $this->dataArg($args, 'updateMany'), isset($args['limit']) ? (int) $args['limit'] : null);

            return $this->readMany($model, $identities, $args);
        });
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    public function upsert(ModelInfo $model, array $args): array
    {
        Reader::assertArgs($args, ['where', 'create', 'update', 'select', 'include', 'omit'], 'upsert');
        $this->assertWritable($model);
        $this->assertSelection($model, $args);
        Reader::assertUnique($model, $args['where'] ?? null, 'upsert');

        return $this->engine->transaction(function () use ($model, $args) {
            $row = $this->findRow($model, $args['where']);
            $identity = $row !== null
                ? $this->applyUpdate($model, $row, (array) ($args['update'] ?? []))
                : $this->insert($model, (array) ($args['create'] ?? []));

            return $this->readBack($model, $identity, $args);
        });
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    public function delete(ModelInfo $model, array $args): array
    {
        Reader::assertArgs($args, ['where', 'select', 'include', 'omit'], 'delete');
        $this->assertWritable($model);
        $this->assertSelection($model, $args);
        Reader::assertUnique($model, $args['where'] ?? null, 'delete');

        return $this->engine->transaction(function () use ($model, $args) {
            $row = $this->findRow($model, $args['where']) ?? throw $this->notFound($model, 'Record to delete does not exist.');
            $record = $this->readBack($model, $this->identityOf($model, $row), $args);
            $this->deleteRows($model, [$row]);

            return $record;
        });
    }

    /** @param array<string, mixed> $args */
    public function deleteMany(ModelInfo $model, array $args): int
    {
        Reader::assertArgs($args, ['where', 'limit'], 'deleteMany');
        $this->assertWritable($model);
        $this->assertSelection($model, $args);

        return $this->engine->transaction(fn () => $this->deleteWhere($model, (array) ($args['where'] ?? []), isset($args['limit']) ? (int) $args['limit'] : null));
    }

    // ---- helpers ---------------------------------------------------------------------------------

    /** select / include / omit are checked before anything is written. @param array<string, mixed> $args */
    private function assertSelection(ModelInfo $model, array $args): void
    {
        $read = array_intersect_key($args, array_flip(['select', 'include', 'omit']));
        if ($read !== []) {
            $this->reader->selection($model, $read);
        }
    }

    private function assertWritable(ModelInfo $model): void
    {
        if ($model->model->isView) {
            throw new PrismaClientValidationError(sprintf('`%s` is a view: only read operations are available.', $model->name));
        }
        if ($model->identity() === [] || ($model->primaryKey === [] && $model->uniqueKeys === [])) {
            throw new PrismaClientValidationError(sprintf('The model `%s` has no unique criteria, it cannot be written by the client.', $model->name));
        }
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function dataArg(array $args, string $operation): array
    {
        $data = $args['data'] ?? null;
        if (! is_array($data)) {
            throw new PrismaClientValidationError(sprintf('Argument `data` is missing in `%s()`.', $operation));
        }

        return $data;
    }

    /** @return list<array<string, mixed>> */
    private function listArg(mixed $value, string $name): array
    {
        if (! is_array($value)) {
            throw new PrismaClientValidationError("Argument `$name` must be an object or a list of objects.");
        }

        return array_is_list($value) ? $value : [$value];
    }

    /** @return list<mixed> */
    private static function items(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [$value];
    }

    private function notFound(ModelInfo $model, string $cause): PrismaClientKnownRequestError
    {
        return PrismaClientKnownRequestError::create('P2025', 'An operation failed because it depends on one or more records that were required but not found. ' . $cause, ['modelName' => $model->name, 'cause' => $cause]);
    }

    /** The record after a write, shaped by select / include / omit. @param array<string, mixed> $identity @param array<string, mixed> $args @return array<string, mixed> */
    private function readBack(ModelInfo $model, array $identity, array $args): array
    {
        $read = array_intersect_key($args, array_flip(['select', 'include', 'omit']));
        $read['where'] = $identity;
        $read['take'] = 1;

        return $this->reader->findMany($model, $read)[0]
            ?? throw $this->notFound($model, 'The record was not found after the write.');
    }

    /** @param list<array<string, mixed>> $identities @param array<string, mixed> $args @return list<array<string, mixed>> */
    private function readMany(ModelInfo $model, array $identities, array $args): array
    {
        if ($identities === []) {
            return [];
        }
        $read = array_intersect_key($args, array_flip(['select', 'include', 'omit']));
        $selection = $this->reader->selection($model, $read);
        $fields = $selection->columns($model, $this->reader->computedFor($model));
        $identity = $model->identity();
        $rows = [];
        foreach (array_chunk($identities, self::CHUNK) as $chunk) {
            foreach ($this->reader->rows($model, ['where' => $this->identitiesWhere($model, $chunk)], $fields) as $row) {
                $rows[Reader::key($this->identityOf($model, $row, true), $identity)] = $row;
            }
        }
        $ordered = [];
        foreach ($identities as $id) {
            $key = Reader::key($this->encodedIdentity($model, $id), $identity);
            if (isset($rows[$key])) {
                $ordered[] = $rows[$key];
            }
        }

        return $this->reader->hydrate($model, $ordered, $selection);
    }

    /** Raw row (all scalar fields) matching a where, or null. @param array<string, mixed> $where @return array<string, mixed>|null */
    private function findRow(ModelInfo $model, array $where): ?array
    {
        return $this->reader->rows($model, ['where' => $where, 'take' => 1], array_keys($model->scalars))[0] ?? null;
    }

    /** @param array<string, mixed> $where @return list<array<string, mixed>> */
    private function findRows(ModelInfo $model, array $where, ?int $limit = null): array
    {
        $args = ['where' => $where];
        if ($limit !== null) {
            $args['take'] = $limit;
        }

        return $this->reader->rows($model, $args, array_keys($model->scalars));
    }

    /**
     * Identity of a raw row as decoded values (usable as a where), or raw (for keys) with $raw.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function identityOf(ModelInfo $model, array $row, bool $raw = false): array
    {
        $identity = [];
        foreach ($model->identity() as $field) {
            $identity[$field] = $raw ? ($row[$field] ?? null) : $this->engine->codec->decode($model->scalars[$field], $row[$field] ?? null);
        }

        return $identity;
    }

    /** Decoded identity → the database form (to compare with raw rows). @param array<string, mixed> $identity @return array<string, mixed> */
    private function encodedIdentity(ModelInfo $model, array $identity): array
    {
        $out = [];
        foreach ($identity as $field => $value) {
            $encoded = $this->engine->codec->encode($model->scalars[$field], $value);
            $out[$field] = $encoded instanceof \Nesto\Orm\Types\Binary ? $encoded->bytes : $encoded;
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $identities @return array<string, mixed> */
    private function identitiesWhere(ModelInfo $model, array $identities): array
    {
        $fields = $model->identity();
        if (count($fields) === 1) {
            return [$fields[0] => ['in' => array_map(fn (array $id) => $id[$fields[0]], $identities)]];
        }

        return ['OR' => array_values($identities)];
    }

    /** Decoded record of a raw row (all scalars). @param array<string, mixed> $row @return array<string, mixed> */
    private function decodeRow(ModelInfo $model, array $row): array
    {
        $out = [];
        foreach ($model->scalars as $name => $field) {
            $out[$name] = $this->engine->codec->decode($field, $row[$name] ?? null);
        }

        return $out;
    }

    /** @param array<string, mixed> $identity @return array<string, mixed> decoded row */
    private function rowByIdentity(ModelInfo $model, array $identity): array
    {
        $row = $this->findRow($model, $identity) ?? throw $this->notFound($model, 'The record was not found.');

        return $this->decodeRow($model, $row);
    }

    // ---- create ----------------------------------------------------------------------------------

    /**
     * Insert one record with its nested writes; returns its identity (decoded values).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $link foreign key values set by the parent of a nested create
     * @return array<string, mixed>
     */
    private function insert(ModelInfo $model, array $data, array $link = []): array
    {
        $values = [];
        $after = [];
        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (($field = $model->scalar($key)) !== null) {
                if ($field->isList && is_array($value) && ! array_is_list($value) && array_key_exists('set', $value)) {
                    $value = $value['set'];
                }
                $values[$key] = $value;
            } elseif (($relation = $model->relation($key)) !== null) {
                if ($relation->kind === RelationInfo::OWNER) {
                    $this->ownerOnCreate($relation, (array) $value, $values);
                } else {
                    $after[$key] = $value;
                }
            } else {
                throw $this->unknownField($model, $key, 'create');
            }
        }
        foreach ($link as $field => $value) {
            $values[$field] = $value;
        }
        $values = $this->withDefaults($model, $values);
        $this->assertRequired($model, $values);
        $identity = $this->insertRow($model, $values);
        if ($after !== []) {
            $parent = $this->rowByIdentity($model, $identity);
            foreach ($after as $name => $ops) {
                $this->nested($model, $model->relations[$name], $parent, (array) $ops, true);
            }
        }

        return $identity;
    }

    /** Client-side defaults: uuid(), cuid(), nanoid(), ulid(), now() and @updatedAt. @param array<string, mixed> $values @return array<string, mixed> */
    private function withDefaults(ModelInfo $model, array $values): array
    {
        foreach ($model->scalars as $name => $field) {
            if (array_key_exists($name, $values)) {
                continue;
            }
            if ($field->isUpdatedAt) {
                $values[$name] = ValueCodec::now();
                continue;
            }
            $default = $field->defaultFunction();
            if ($default === null) {
                continue;
            }
            $generated = self::generateDefault($default);
            if ($generated !== null) {
                $values[$name] = $generated;
            }
        }

        return $values;
    }

    /** A value for a client-side default function, null when the database provides it. */
    public static function generateDefault(FieldDefault $default): mixed
    {
        return match ($default->name) {
            'uuid' => (int) ($default->args[0] ?? 4) === 7 ? Ids::uuid7() : Ids::uuid4(),
            'cuid' => (int) ($default->args[0] ?? 1) === 2 ? Ids::cuid2() : Ids::cuid(),
            'nanoid' => Ids::nanoid((int) ($default->args[0] ?? 21)),
            'ulid' => Ids::ulid(),
            'now' => ValueCodec::now(),
            default => null,
        };
    }

    /** @param array<string, mixed> $values */
    private function assertRequired(ModelInfo $model, array $values): void
    {
        foreach ($model->scalars as $name => $field) {
            if (! $field->isRequired || $field->hasDefaultValue || $field->isUpdatedAt || $field->isList || array_key_exists($name, $values)) {
                continue;
            }
            $argument = $name;
            foreach ($model->relations as $relation) {
                if ($relation->kind === RelationInfo::OWNER && in_array($name, $relation->thisFields(), true)) {
                    $argument = $relation->name();
                    break;
                }
            }
            throw new PrismaClientValidationError(sprintf('Argument `%s` is missing in the data of `%s` (create).', $argument, $model->name));
        }
        foreach ($values as $name => $value) {
            $field = $model->scalars[$name];
            if ($value === null && $field->isRequired && ! $field->isList) {
                throw new PrismaClientValidationError(sprintf('Argument `%s` must not be null (field `%s.%s` is required).', $name, $model->name, $name));
            }
        }
    }

    /**
     * INSERT one row; the identity comes from the values, RETURNING (PostgreSQL / SQLite) or the last insert id.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function insertRow(ModelInfo $model, array $values): array
    {
        $q = fn (string $id) => $this->engine->quote($id);
        $columns = [];
        $params = [];
        foreach ($values as $name => $value) {
            $columns[] = $q($model->column($name));
            $params[] = $this->engine->codec->encode($model->scalars[$name], $value);
        }
        $platform = $this->engine->platform;
        $sql = 'INSERT INTO ' . $q($model->table) . ($columns === []
            ? ($platform === 'mysql' || $platform === 'mariadb' ? ' () VALUES ()' : ' DEFAULT VALUES')
            : ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
        $identityFields = $model->identity();
        $known = array_diff($identityFields, array_keys($values)) === [];
        if ($known) {
            $this->engine->execute($sql, $params);

            return array_intersect_key($values, array_flip($identityFields));
        }
        if ($platform === 'pgsql' || $platform === 'sqlite') {
            $sql .= ' RETURNING ' . implode(', ', array_map(fn (string $f) => $q($model->column($f)) . ' AS ' . $q($f), $identityFields));
            $row = $this->engine->select($sql, $params)[0] ?? [];

            return $this->identityOf($model, $row);
        }
        $this->engine->execute($sql, $params);
        $identity = [];
        foreach ($identityFields as $field) {
            if (array_key_exists($field, $values)) {
                $identity[$field] = $values[$field];
            } elseif ($model->scalars[$field]->isAutoIncrement()) {
                $identity[$field] = $this->engine->codec->decode($model->scalars[$field], $this->engine->connection()->lastInsertId());
            } else {
                // a database-generated key we cannot read back: find the row by another unique criterion
                foreach ($model->uniqueKeys as $fields) {
                    if (array_diff($fields, array_keys($values)) === []) {
                        $row = $this->findRow($model, array_intersect_key($values, array_flip($fields)));

                        return $row !== null ? $this->identityOf($model, $row) : throw $this->notFound($model, 'The created record could not be read back.');
                    }
                }
                throw new PrismaClientValidationError(sprintf('Cannot read back the created `%s`: its identity is generated by the database and there is no other unique value.', $model->name));
            }
        }

        return $identity;
    }

    /**
     * Multi-row INSERTs (rows grouped by their column set); returns [inserted count, identities].
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $link
     * @return array{0: int, 1: list<array<string, mixed>>}
     */
    private function insertMany(ModelInfo $model, array $rows, bool $skipDuplicates, bool $returning = false, array $link = []): array
    {
        $groups = [];
        foreach ($rows as $data) {
            if (! is_array($data)) {
                throw new PrismaClientValidationError('Every item of `data` must be an object.');
            }
            $values = [];
            foreach ($data as $key => $value) {
                $key = (string) $key;
                $field = $model->scalar($key) ?? throw $this->unknownField($model, $key, 'createMany', 'Nested writes are not available in createMany().');
                if ($field->isList && is_array($value) && ! array_is_list($value) && array_key_exists('set', $value)) {
                    $value = $value['set'];
                }
                $values[$key] = $value;
            }
            $values = $this->withDefaults($model, $link + $values);
            $this->assertRequired($model, $values);
            ksort($values);
            $groups[implode(',', array_keys($values))][] = $values;
        }
        $q = fn (string $id) => $this->engine->quote($id);
        $platform = $this->engine->platform;
        $count = 0;
        $identities = [];
        $identityFields = $model->identity();
        foreach ($groups as $group) {
            $names = array_keys($group[0]);
            $width = max(1, count($names));
            foreach (array_chunk($group, max(1, intdiv(30000, $width))) as $chunk) {
                $params = [];
                $tuples = [];
                foreach ($chunk as $values) {
                    foreach ($values as $name => $value) {
                        $params[] = $this->engine->codec->encode($model->scalars[$name], $value);
                    }
                    $tuples[] = '(' . implode(', ', array_fill(0, count($values), '?')) . ')';
                }
                if ($names === []) {
                    foreach ($chunk as $_) {
                        $identities[] = $this->insertRow($model, []);
                        $count++;
                    }
                    continue;
                }
                $verb = match (true) {
                    $skipDuplicates && $platform === 'sqlite' => 'INSERT OR IGNORE INTO ',
                    $skipDuplicates && ($platform === 'mysql' || $platform === 'mariadb') => 'INSERT IGNORE INTO ',
                    default => 'INSERT INTO ',
                };
                $sql = $verb . $q($model->table) . ' (' . implode(', ', array_map(fn ($n) => $q($model->column($n)), $names)) . ') VALUES ' . implode(', ', $tuples);
                if ($skipDuplicates && $platform === 'pgsql') {
                    $sql .= ' ON CONFLICT DO NOTHING';
                }
                if ($returning) {
                    $sql .= ' RETURNING ' . implode(', ', array_map(fn (string $f) => $q($model->column($f)) . ' AS ' . $q($f), $identityFields));
                    foreach ($this->engine->select($sql, $params) as $row) {
                        $identities[] = $this->identityOf($model, $row);
                        $count++;
                    }
                } else {
                    $count += $this->engine->execute($sql, $params);
                }
            }
        }

        return [$count, $identities];
    }

    /**
     * create / connect / connectOrCreate on a relation whose foreign key is on the record being created.
     *
     * @param array<string, mixed> $ops
     * @param array<string, mixed> $values
     */
    private function ownerOnCreate(RelationInfo $relation, array $ops, array &$values): void
    {
        $target = $this->engine->model($relation->target);
        foreach ($ops as $op => $arg) {
            $related = match ($op) {
                'create' => $this->rowByIdentity($target, $this->insert($target, (array) $arg)),
                'connect' => $this->connectTarget($target, (array) $arg, $relation),
                'connectOrCreate' => $this->connectOrCreateTarget($target, (array) $arg),
                default => throw new PrismaClientValidationError(sprintf('`%s` is not available on the relation `%s` in a create; use create, connect or connectOrCreate.', $op, $relation->name())),
            };
            foreach ($relation->pairs as [$thisField, $otherField]) {
                $values[$thisField] = $related[$otherField];
            }
        }
    }

    /** @param array<string, mixed> $where @return array<string, mixed> decoded row */
    private function connectTarget(ModelInfo $target, array $where, RelationInfo $relation): array
    {
        Reader::assertUnique($target, $where, 'connect');
        $row = $this->findRow($target, $where);
        if ($row === null) {
            $cause = sprintf("No '%s' record(s) (needed to inline the relation on '%s' record(s)) was found for a nested connect on relation '%s'.", $target->name, $relation->model, $relation->field->relationName);

            throw PrismaClientKnownRequestError::create('P2025', 'An operation failed because it depends on one or more records that were required but not found. ' . $cause, ['modelName' => $relation->model, 'cause' => $cause]);
        }

        return $this->decodeRow($target, $row);
    }

    /** @param array<string, mixed> $arg @return array<string, mixed> decoded row */
    private function connectOrCreateTarget(ModelInfo $target, array $arg): array
    {
        $where = (array) ($arg['where'] ?? []);
        Reader::assertUnique($target, $where, 'connectOrCreate');
        $row = $this->findRow($target, $where);

        return $row !== null ? $this->decodeRow($target, $row) : $this->rowByIdentity($target, $this->insert($target, (array) ($arg['create'] ?? [])));
    }

    // ---- update ----------------------------------------------------------------------------------

    /**
     * Apply `data` to one row (raw row as read); returns the (possibly changed) identity.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function applyUpdate(ModelInfo $model, array $row, array $data): array
    {
        $current = $this->decodeRow($model, $row);
        $identity = $this->identityOf($model, $row);
        $set = [];
        $after = [];
        $deletes = [];
        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (($field = $model->scalar($key)) !== null) {
                $set[$key] = $value;
            } elseif (($relation = $model->relation($key)) !== null) {
                if ($relation->kind === RelationInfo::OWNER) {
                    $this->ownerOnUpdate($model, $relation, $current, (array) $value, $set, $deletes);
                } else {
                    $after[$key] = $value;
                }
            } else {
                throw $this->unknownField($model, $key, 'update');
            }
        }
        $identity = $this->updateByIdentities($model, [$identity], $set)[0] ?? $identity;
        // related records deleted through `delete: true` go after the foreign key was cleared
        foreach ($deletes as [$deleteModel, $deleteRow]) {
            $this->deleteRows($deleteModel, [$deleteRow]);
        }
        if ($after !== []) {
            $parent = $this->rowByIdentity($model, $identity);
            foreach ($after as $name => $ops) {
                $this->nested($model, $model->relations[$name], $parent, (array) $ops, false);
            }
        }

        return $identity;
    }

    /**
     * UPDATE the rows with these identities (scalar data incl. atomic operations; @updatedAt is set).
     * Returns the identities after the update.
     *
     * @param list<array<string, mixed>> $identities
     * @param array<string, mixed>       $set field => value | {set|increment|decrement|multiply|divide|push: x}
     * @return list<array<string, mixed>>
     */
    private function updateByIdentities(ModelInfo $model, array $identities, array $set): array
    {
        if ($identities === [] || $set === []) {
            return $identities;
        }
        foreach ($model->scalars as $name => $field) {
            if ($field->isUpdatedAt && ! array_key_exists($name, $set)) {
                $set[$name] = ValueCodec::now();
            }
        }
        $q = fn (string $id) => $this->engine->quote($id);
        $assignments = [];
        $params = [];
        $plainValues = [];
        foreach ($set as $name => $value) {
            $field = $model->scalars[$name];
            $column = $q($field->columnName());
            [$sql, $values, $plain, $plainValue] = $this->assignment($model, $field, $column, $value);
            $assignments[] = "$column = $sql";
            array_push($params, ...$values);
            if ($plain) {
                $plainValues[$name] = $plainValue;
            }
        }
        $c = new QueryCompiler($this->engine);
        $table = $q($model->table);
        foreach (array_chunk($identities, self::CHUNK) as $chunk) {
            $whereParams = [];
            $where = $c->where($model, $this->identitiesWhere($model, $chunk), $table, $whereParams);
            $this->engine->execute('UPDATE ' . $table . ' SET ' . implode(', ', $assignments) . ' WHERE ' . $where, [...$params, ...$whereParams]);
        }
        // identities after the update (an id field may have been set)
        return array_map(function (array $identity) use ($plainValues) {
            foreach ($identity as $field => $_) {
                if (array_key_exists($field, $plainValues)) {
                    $identity[$field] = $plainValues[$field];
                }
            }

            return $identity;
        }, $identities);
    }

    /**
     * SQL of `column = ...` for an update value: a value, or an atomic operation.
     *
     * @return array{0: string, 1: list<mixed>, 2: bool, 3: mixed} [sql, params, is a plain value, the value]
     */
    private function assignment(ModelInfo $model, Field $field, string $column, mixed $value): array
    {
        $codec = $this->engine->codec;
        $isOperation = is_array($value) && ! array_is_list($value) && $field->type !== 'Json' && count($value) === 1
            && in_array(array_key_first($value), ['set', 'increment', 'decrement', 'multiply', 'divide', 'push', 'unset'], true);
        if ($field->isList) {
            if (! $isOperation) {
                return ['?', [$codec->encode($field, $value)], true, $value];
            }
            $op = (string) array_key_first($value);
            $operand = $value[$op];

            return match ($op) {
                'set' => ['?', [$codec->encode($field, $operand)], true, $operand],
                'push' => ['array_cat(' . $column . ', ?)', [$codec->encodeList($field, is_array($operand) ? $operand : [$operand])], false, null],
                'unset' => ['NULL', [], true, null],
                default => throw new PrismaClientValidationError(sprintf('`%s` is not available on the list field `%s`.', $op, $field->name)),
            };
        }
        if (! $isOperation) {
            if ($value === null && $field->isRequired) {
                throw new PrismaClientValidationError(sprintf('Argument `%s` must not be null (field `%s.%s` is required).', $field->name, $model->name, $field->name));
            }

            return ['?', [$codec->encode($field, $value)], true, $value];
        }
        $op = (string) array_key_first($value);
        $operand = $value[$op];
        if ($op === 'set') {
            return ['?', [$codec->encode($field, $operand)], true, $operand];
        }
        if (! in_array($field->type, ['Int', 'BigInt', 'Float', 'Decimal'], true)) {
            throw new PrismaClientValidationError(sprintf('`%s` is only available on numeric fields, `%s` is %s.', $op, $field->name, $field->type));
        }
        $placeholder = $field->type === 'Decimal' && in_array($this->engine->platform, ['mysql', 'mariadb'], true) ? 'CAST(? AS DECIMAL(65,30))' : '?';
        $operator = match ($op) {
            'increment' => '+',
            'decrement' => '-',
            'multiply' => '*',
            'divide' => '/',
            default => throw new PrismaClientValidationError(sprintf('Unknown update operation `%s` on field `%s`.', $op, $field->name)),
        };
        $sql = "$column $operator $placeholder";
        if ($op === 'divide' && in_array($field->type, ['Int', 'BigInt'], true) && in_array($this->engine->platform, ['mysql', 'mariadb'], true)) {
            $sql = "$column DIV $placeholder"; // integer division like PostgreSQL / SQLite
        }

        return [$sql, [$codec->encodeScalar($field, $operand)], false, null];
    }

    /**
     * Nested writes on a relation whose foreign key is on the record being updated.
     *
     * @param array<string, mixed> $current decoded row
     * @param array<string, mixed> $ops
     * @param array<string, mixed> $set
     * @param list<array{0: ModelInfo, 1: array<string, mixed>}> $deletes records to delete after the update
     */
    private function ownerOnUpdate(ModelInfo $model, RelationInfo $relation, array $current, array $ops, array &$set, array &$deletes): void
    {
        $target = $this->engine->model($relation->target);
        $linked = function () use ($relation, $current): ?array {
            $where = [];
            foreach ($relation->pairs as [$thisField, $otherField]) {
                if ($current[$thisField] === null) {
                    return null;
                }
                $where[$otherField] = $current[$thisField];
            }

            return $where;
        };
        $link = function (?array $related) use ($relation, &$set): void {
            foreach ($relation->pairs as [$thisField, $otherField]) {
                $set[$thisField] = $related === null ? null : $related[$otherField];
            }
        };
        foreach ($ops as $op => $arg) {
            switch ($op) {
                case 'create':
                    $link($this->rowByIdentity($target, $this->insert($target, (array) $arg)));
                    break;
                case 'connect':
                    $link($this->connectTarget($target, (array) $arg, $relation));
                    break;
                case 'connectOrCreate':
                    $link($this->connectOrCreateTarget($target, (array) $arg));
                    break;
                case 'disconnect':
                    if ($arg === false) {
                        break;
                    }
                    $this->assertOptional($model, $relation);
                    if (is_array($arg) && $linked() !== null && $this->findRow($target, ['AND' => [$linked(), $arg]]) === null) {
                        break; // disconnect only when the related record matches
                    }
                    $link(null);
                    break;
                case 'update':
                    $where = $linked() ?? throw $this->nestedNotFound($target, $relation, 'update');
                    $data = is_array($arg) && array_key_exists('data', $arg) ? (array) $arg['data'] : (array) $arg;
                    if (is_array($arg) && isset($arg['where'])) {
                        $where = ['AND' => [$where, (array) $arg['where']]];
                    }
                    $row = $this->findRow($target, $where) ?? throw $this->nestedNotFound($target, $relation, 'update');
                    $this->applyUpdate($target, $row, $data);
                    break;
                case 'upsert':
                    $where = $linked();
                    $row = $where !== null ? $this->findRow($target, isset($arg['where']) ? ['AND' => [$where, (array) $arg['where']]] : $where) : null;
                    if ($row !== null) {
                        $this->applyUpdate($target, $row, (array) ($arg['update'] ?? []));
                    } else {
                        $link($this->rowByIdentity($target, $this->insert($target, (array) ($arg['create'] ?? []))));
                    }
                    break;
                case 'delete':
                    if ($arg === false) {
                        break;
                    }
                    $this->assertOptional($model, $relation);
                    $where = $linked() ?? throw $this->nestedNotFound($target, $relation, 'delete');
                    if (is_array($arg)) {
                        $where = ['AND' => [$where, $arg]];
                    }
                    $row = $this->findRow($target, $where) ?? throw $this->nestedNotFound($target, $relation, 'delete');
                    $link(null);
                    $deletes[] = [$target, $row];
                    break;
                default:
                    throw new PrismaClientValidationError(sprintf('Unknown nested operation `%s` on the relation `%s.%s`.', $op, $model->name, $relation->name()));
            }
        }
    }

    private function assertOptional(ModelInfo $model, RelationInfo $relation): void
    {
        if ($relation->fkRequired) {
            throw PrismaClientKnownRequestError::create('P2014', sprintf(
                "The change you are trying to make would violate the required relation '%s' between the `%s` and `%s` models.",
                $relation->field->relationName,
                $model->name,
                $relation->target,
            ), ['relation_name' => $relation->field->relationName, 'model_a_name' => $model->name, 'model_b_name' => $relation->target]);
        }
    }

    private function nestedNotFound(ModelInfo $target, RelationInfo $relation, string $operation): PrismaClientKnownRequestError
    {
        $cause = sprintf("No '%s' record was found for a nested %s on relation '%s'.", $target->name, $operation, $relation->field->relationName);

        return PrismaClientKnownRequestError::create('P2025', 'An operation failed because it depends on one or more records that were required but not found. ' . $cause, ['modelName' => $target->name, 'cause' => $cause]);
    }

    // ---- nested writes on the other side ---------------------------------------------------------

    /**
     * Nested writes on a relation whose foreign key is on the related records (to-one back side, one-to-many,
     * implicit many-to-many).
     *
     * @param array<string, mixed> $parent decoded parent row
     * @param array<string, mixed> $ops
     */
    private function nested(ModelInfo $model, RelationInfo $relation, array $parent, array $ops, bool $creating): void
    {
        $target = $this->engine->model($relation->target);
        $m2m = $relation->kind === RelationInfo::M2M;
        $link = [];
        $scope = [];
        if ($m2m) {
            $scope = [(string) $relation->opposite => ['some' => array_intersect_key($parent, array_flip($model->identity()))]];
        } else {
            foreach ($relation->pairs as [$thisField, $otherField]) {
                $link[$otherField] = $parent[$thisField];
            }
            $scope = $link;
        }
        $parentId = $m2m ? $parent[$relation->pairs[0][0]] : null;
        $within = fn (?array $where) => $where === null || $where === [] ? $scope : ['AND' => [$scope, $where]];
        foreach ($ops as $op => $arg) {
            if (! in_array($op, self::NESTED_OPS, true)) {
                throw new PrismaClientValidationError(sprintf('Unknown nested operation `%s` on the relation `%s.%s`.', $op, $model->name, $relation->name()));
            }
            if ($creating && in_array($op, ['disconnect', 'set', 'update', 'updateMany', 'upsert', 'delete', 'deleteMany'], true)) {
                throw new PrismaClientValidationError(sprintf('`%s` is not available on the relation `%s.%s` in a create.', $op, $model->name, $relation->name()));
            }
            switch ($op) {
                case 'create':
                    foreach ($relation->isList() ? self::items($arg) : [$arg] as $data) {
                        if (! $relation->isList() && ! $creating) {
                            $this->detachBack($relation, $target, $scope);
                        }
                        $identity = $this->insert($target, (array) $data, $link);
                        if ($m2m) {
                            $this->joinInsert($relation, $parentId, $this->rowByIdentity($target, $identity)[$relation->pairs[0][1]]);
                        }
                    }
                    break;
                case 'createMany':
                    if ($m2m) {
                        throw new PrismaClientValidationError(sprintf('`createMany` is not available on the many-to-many relation `%s.%s`.', $model->name, $relation->name()));
                    }
                    $this->insertMany($target, $this->listArg($arg['data'] ?? null, 'data'), (bool) ($arg['skipDuplicates'] ?? false), false, $link);
                    break;
                case 'connect':
                    $items = $relation->isList() ? self::items($arg) : [$arg];
                    $found = 0;
                    $rows = [];
                    foreach ($items as $where) {
                        Reader::assertUnique($target, $where, 'connect');
                        $row = $this->findRow($target, (array) $where);
                        if ($row !== null) {
                            $found++;
                            $rows[] = $row;
                        }
                    }
                    if ($found < count($items)) {
                        $cause = sprintf('Expected %d records to be connected, found only %d.', count($items), $found);
                        throw PrismaClientKnownRequestError::create('P2025', 'An operation failed because it depends on one or more records that were required but not found. ' . $cause, ['modelName' => $model->name, 'cause' => $cause]);
                    }
                    foreach ($rows as $row) {
                        $this->connectRow($relation, $target, $row, $link, $scope, $parentId);
                    }
                    break;
                case 'connectOrCreate':
                    foreach ($relation->isList() ? self::items($arg) : [$arg] as $item) {
                        $where = (array) ($item['where'] ?? []);
                        Reader::assertUnique($target, $where, 'connectOrCreate');
                        $row = $this->findRow($target, $where);
                        if ($row !== null) {
                            $this->connectRow($relation, $target, $row, $link, $scope, $parentId);
                            continue;
                        }
                        if (! $relation->isList() && ! $creating) {
                            $this->detachBack($relation, $target, $scope);
                        }
                        $identity = $this->insert($target, (array) ($item['create'] ?? []), $link);
                        if ($m2m) {
                            $this->joinInsert($relation, $parentId, $this->rowByIdentity($target, $identity)[$relation->pairs[0][1]]);
                        }
                    }
                    break;
                case 'disconnect':
                    if ($arg === false) {
                        break;
                    }
                    if ($m2m) {
                        foreach (self::items($arg) as $where) {
                            $row = $this->findRow($target, $within((array) $where));
                            if ($row !== null) {
                                $this->joinDelete($relation, $parentId, [$this->decodeRow($target, $row)[$relation->pairs[0][1]]]);
                            }
                        }
                        break;
                    }
                    $this->assertOptional($model, $relation);
                    $wheres = $relation->isList() ? self::items($arg) : [$arg === true ? [] : (array) $arg];
                    foreach ($wheres as $where) {
                        $rows = $this->findRows($target, $within((array) $where));
                        $this->updateByIdentities($target, array_map(fn ($r) => $this->identityOf($target, $r), $rows), array_fill_keys(array_keys($link), null));
                    }
                    break;
                case 'set':
                    $items = self::items($arg ?? []);
                    if ($m2m) {
                        $this->joinDelete($relation, $parentId, null);
                    } else {
                        $this->assertOptional($model, $relation);
                        $rows = $this->findRows($target, $scope);
                        $this->updateByIdentities($target, array_map(fn ($r) => $this->identityOf($target, $r), $rows), array_fill_keys(array_keys($link), null));
                    }
                    foreach ($items as $where) {
                        if ($where === [] || $where === null) {
                            continue;
                        }
                        Reader::assertUnique($target, $where, 'set');
                        $row = $this->findRow($target, (array) $where);
                        if ($row === null) {
                            $cause = 'Expected 1 records to be connected, found only 0.';
                            throw PrismaClientKnownRequestError::create('P2025', 'An operation failed because it depends on one or more records that were required but not found. ' . $cause, ['modelName' => $model->name, 'cause' => $cause]);
                        }
                        $this->connectRow($relation, $target, $row, $link, $scope, $parentId);
                    }
                    break;
                case 'update':
                    foreach ($relation->isList() ? self::items($arg) : [$arg] as $item) {
                        $item = (array) $item;
                        $hasEnvelope = array_key_exists('data', $item) && ($relation->isList() || array_diff(array_keys($item), ['where', 'data']) === []);
                        $data = $hasEnvelope ? (array) $item['data'] : $item;
                        $where = $hasEnvelope ? ($item['where'] ?? null) : null;
                        $row = $this->findRow($target, $within($where === null ? null : (array) $where)) ?? throw $this->nestedNotFound($target, $relation, 'update');
                        $this->applyUpdate($target, $row, $data);
                    }
                    break;
                case 'updateMany':
                    foreach (self::items($arg) as $item) {
                        $this->updateRows($target, $within((array) ($item['where'] ?? [])), (array) ($item['data'] ?? []), null);
                    }
                    break;
                case 'upsert':
                    foreach ($relation->isList() ? self::items($arg) : [$arg] as $item) {
                        $item = (array) $item;
                        $row = $this->findRow($target, $within(isset($item['where']) ? (array) $item['where'] : null));
                        if ($row !== null) {
                            $this->applyUpdate($target, $row, (array) ($item['update'] ?? []));
                            continue;
                        }
                        $identity = $this->insert($target, (array) ($item['create'] ?? []), $link);
                        if ($m2m) {
                            $this->joinInsert($relation, $parentId, $this->rowByIdentity($target, $identity)[$relation->pairs[0][1]]);
                        }
                    }
                    break;
                case 'delete':
                    if ($arg === false) {
                        break;
                    }
                    foreach ($relation->isList() ? self::items($arg) : [$arg === true ? [] : $arg] as $where) {
                        $row = $this->findRow($target, $within((array) $where)) ?? throw $this->nestedNotFound($target, $relation, 'delete');
                        $this->deleteRows($target, [$row]);
                    }
                    break;
                case 'deleteMany':
                    foreach (self::items($arg ?? []) as $where) {
                        $this->deleteWhere($target, $within($where === true ? [] : (array) $where), null);
                    }
                    break;
            }
        }
    }

    /**
     * Link an existing related row to the parent: set its foreign key, or insert the join row.
     *
     * @param array<string, mixed> $row raw related row
     * @param array<string, mixed> $link
     * @param array<string, mixed> $scope
     */
    private function connectRow(RelationInfo $relation, ModelInfo $target, array $row, array $link, array $scope, mixed $parentId): void
    {
        if ($relation->kind === RelationInfo::M2M) {
            $this->joinInsert($relation, $parentId, $this->decodeRow($target, $row)[$relation->pairs[0][1]], true);

            return;
        }
        if ($relation->kind === RelationInfo::BACK) {
            $this->detachBack($relation, $target, $scope, $row);
        }
        $this->updateByIdentities($target, [$this->identityOf($target, $row)], $link);
    }

    /**
     * One-to-one, foreign key on the other side: the record currently linked to the parent loses the link
     * (its foreign key is set to null; with a required foreign key the database refuses, like Prisma's P2014).
     *
     * @param array<string, mixed>      $scope
     * @param array<string, mixed>|null $except raw row of the record about to be linked
     */
    private function detachBack(RelationInfo $relation, ModelInfo $target, array $scope, ?array $except = null): void
    {
        $fields = $target->identity();
        $exceptKey = $except !== null ? Reader::key($except, $fields) : null;
        $identities = [];
        foreach ($this->findRows($target, $scope) as $row) {
            if (Reader::key($row, $fields) !== $exceptKey) {
                $identities[] = $this->identityOf($target, $row);
            }
        }
        if ($identities === []) {
            return;
        }
        if ($relation->fkRequired) {
            throw PrismaClientKnownRequestError::create('P2014', sprintf(
                "The change you are trying to make would violate the required relation '%s' between the `%s` and `%s` models.",
                $relation->field->relationName,
                $relation->model,
                $target->name,
            ), ['relation_name' => $relation->field->relationName, 'model_a_name' => $relation->model, 'model_b_name' => $target->name]);
        }
        $this->updateByIdentities($target, $identities, array_fill_keys($relation->otherFields(), null));
    }

    private function joinInsert(RelationInfo $relation, mixed $parentId, mixed $childId, bool $ignoreExisting = false): void
    {
        $q = fn (string $id) => $this->engine->quote($id);
        $table = $q((string) $relation->joinTable);
        $thisColumn = (string) $relation->thisColumn;
        $otherColumn = (string) $relation->otherColumn;
        $thisField = $this->engine->model($relation->model)->scalars[$relation->pairs[0][0]];
        $otherField = $this->engine->model($relation->target)->scalars[$relation->pairs[0][1]];
        $codec = $this->engine->codec;
        $params = [$codec->encode($thisField, $parentId), $codec->encode($otherField, $childId)];
        if ($ignoreExisting) {
            $exists = $this->engine->select('SELECT 1 FROM ' . $table . ' WHERE ' . $q($thisColumn) . ' = ? AND ' . $q($otherColumn) . ' = ?', $params);
            if ($exists !== []) {
                return;
            }
        }
        $this->engine->execute('INSERT INTO ' . $table . ' (' . $q($thisColumn) . ', ' . $q($otherColumn) . ') VALUES (?, ?)', $params);
    }

    /** @param list<mixed>|null $childIds null: every join row of the parent */
    private function joinDelete(RelationInfo $relation, mixed $parentId, ?array $childIds): void
    {
        $q = fn (string $id) => $this->engine->quote($id);
        $thisField = $this->engine->model($relation->model)->scalars[$relation->pairs[0][0]];
        $otherField = $this->engine->model($relation->target)->scalars[$relation->pairs[0][1]];
        $codec = $this->engine->codec;
        $params = [$codec->encode($thisField, $parentId)];
        $sql = 'DELETE FROM ' . $q((string) $relation->joinTable) . ' WHERE ' . $q((string) $relation->thisColumn) . ' = ?';
        if ($childIds !== null) {
            if ($childIds === []) {
                return;
            }
            $sql .= ' AND ' . $q((string) $relation->otherColumn) . ' IN (' . implode(', ', array_fill(0, count($childIds), '?')) . ')';
            foreach ($childIds as $id) {
                $params[] = $codec->encode($otherField, $id);
            }
        }
        $this->engine->execute($sql, $params);
    }

    // ---- many-row update / delete ----------------------------------------------------------------

    /**
     * updateMany: scalar data on every row matching where (read first, then updated by identity — portable,
     * and MySQL forbids a subquery on the updated table). Returns the identities after the update.
     *
     * @param array<string, mixed> $where
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function updateRows(ModelInfo $model, array $where, array $data, ?int $limit): array
    {
        foreach (array_keys($data) as $key) {
            if ($model->scalar((string) $key) === null) {
                throw $this->unknownField($model, (string) $key, 'updateMany', 'Nested writes are not available in updateMany().');
            }
        }
        $rows = $this->findRows($model, $where, $limit);

        return $this->updateByIdentities($model, array_map(fn ($r) => $this->identityOf($model, $r), $rows), $data);
    }

    /** @param array<string, mixed> $where */
    private function deleteWhere(ModelInfo $model, array $where, ?int $limit): int
    {
        $rows = $this->findRows($model, $where, $limit);
        $this->deleteRows($model, $rows);

        return count($rows);
    }

    /**
     * DELETE rows (raw rows as read). With relationMode "prisma" the onDelete referential actions are
     * emulated first (Cascade, SetNull, SetDefault, Restrict / NoAction) and implicit m2m join rows removed.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function deleteRows(ModelInfo $model, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        if ($this->engine->dataModel->relationMode === 'prisma') {
            $this->emulateOnDelete($model, $rows);
        }
        $q = fn (string $id) => $this->engine->quote($id);
        $table = $q($model->table);
        $c = new QueryCompiler($this->engine);
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $params = [];
            $where = $c->where($model, $this->identitiesWhere($model, array_map(fn ($r) => $this->identityOf($model, $r), $chunk)), $table, $params);
            $this->engine->execute('DELETE FROM ' . $table . ' WHERE ' . $where, $params);
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function emulateOnDelete(ModelInfo $model, array $rows): void
    {
        $decoded = array_map(fn ($r) => $this->decodeRow($model, $r), $rows);
        // implicit many-to-many: the join rows of the deleted records (no foreign keys in this mode)
        foreach ($model->relations as $relation) {
            if ($relation->kind === RelationInfo::M2M) {
                foreach ($decoded as $row) {
                    $this->joinDelete($relation, $row[$relation->pairs[0][0]], null);
                }
            }
        }
        foreach ($this->engine->dataModel->models() as $child) {
            foreach ($child->relations as $relation) {
                if ($relation->target !== $model->name || $relation->kind !== RelationInfo::OWNER) {
                    continue;
                }
                $tuples = [];
                foreach ($decoded as $row) {
                    $where = [];
                    foreach ($relation->pairs as [$fk, $referenced]) {
                        $where[$fk] = $row[$referenced];
                    }
                    $tuples[] = $where;
                }
                $where = ['OR' => $tuples];
                switch ($relation->onDelete) {
                    case 'Cascade':
                        $this->deleteWhere($child, $where, null);
                        break;
                    case 'SetNull':
                        $this->updateRows($child, $where, array_fill_keys($relation->thisFields(), null), null);
                        break;
                    case 'SetDefault':
                        $defaults = [];
                        foreach ($relation->thisFields() as $fk) {
                            $defaults[$fk] = $child->scalars[$fk]->default;
                        }
                        $this->updateRows($child, $where, $defaults, null);
                        break;
                    default: // Restrict, NoAction
                        if ($this->findRows($child, $where, 1) !== []) {
                            throw PrismaClientKnownRequestError::create('P2014', sprintf(
                                "The change you are trying to make would violate the required relation '%s' between the `%s` and `%s` models.",
                                $relation->field->relationName,
                                $child->name,
                                $model->name,
                            ), ['relation_name' => $relation->field->relationName, 'model_a_name' => $child->name, 'model_b_name' => $model->name]);
                        }
                }
            }
        }
    }

    private function unknownField(ModelInfo $model, string $key, string $operation, string $hint = ''): PrismaClientValidationError
    {
        return new PrismaClientValidationError(trim(sprintf(
            'Unknown argument `%s` in the data of `%s()` on model `%s`. Available options: %s. %s',
            $key,
            $operation,
            $model->name,
            implode(', ', [...array_keys($model->scalars), ...array_keys($model->relations)]),
            $hint,
        )));
    }
}
