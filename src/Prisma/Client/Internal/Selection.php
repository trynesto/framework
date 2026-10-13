<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Internal;

use Nesto\Prisma\Client\Errors\PrismaClientValidationError;

/**
 * The shape of returned records, from `select` / `include` / `omit`:
 *
 *   (none)                          every scalar field (+ computed fields of result extensions)
 *   select: { id: true, posts: {...}, _count: { select: { posts: true } } }   only those
 *   include: { posts: true, _count: true }                                    scalars + those relations
 *   omit: { password: true }                                                  scalars minus those
 *
 * @internal
 */
final class Selection
{
    /**
     * @param list<string>                     $scalars   output scalar fields (model order)
     * @param array<string, array<string, mixed>> $relations relation => nested args
     * @param array<string, array<string, mixed>>|null $count relation => `where` (or []) for `_count`
     * @param list<string>                     $computed  result-extension fields to compute
     * @param list<string>                     $keys      output keys in order (select order; else scalars, then include order)
     */
    public function __construct(
        public readonly array $scalars,
        public readonly array $relations,
        public readonly ?array $count,
        public readonly array $computed,
        public readonly array $keys = [],
    ) {}

    /**
     * @param array<string, mixed> $args
     * @param array<string, array{needs: list<string>, compute: \Closure}> $computedFields
     * @param array<string, bool> $defaultOmit the client's global omit for this model
     */
    public static function parse(ModelInfo $model, array $args, array $computedFields = [], array $defaultOmit = []): self
    {
        $select = $args['select'] ?? null;
        $include = $args['include'] ?? null;
        $omit = $args['omit'] ?? null;
        if ($select !== null && $include !== null) {
            throw new PrismaClientValidationError('Please either use `include` or `select`, but not both at the same time.');
        }
        if ($select !== null && $omit !== null) {
            throw new PrismaClientValidationError('Please either use `omit` or `select`, but not both at the same time.');
        }
        $relations = [];
        $count = null;
        $computed = [];
        $keys = [];
        if ($select !== null) {
            if (! is_array($select)) {
                throw new PrismaClientValidationError('Argument `select` must be an object.');
            }
            $wanted = [];
            foreach ($select as $key => $value) {
                if ($value === false || $value === null) {
                    continue;
                }
                $key = (string) $key;
                $keys[] = $key;
                if ($key === '_count') {
                    $count = self::parseCount($model, $value);
                } elseif ($model->scalar($key) !== null) {
                    $wanted[$key] = true;
                } elseif ($model->relation($key) !== null) {
                    $relations[$key] = self::nestedArgs($value, $key);
                } elseif (isset($computedFields[$key])) {
                    $computed[] = $key;
                } else {
                    throw self::unknown($model, $key, 'select');
                }
            }
            $scalars = array_values(array_filter(array_keys($model->scalars), fn (string $f) => isset($wanted[$f])));
        } else {
            $omitted = array_filter($defaultOmit, fn ($v) => $v === true);
            if ($omit !== null) {
                foreach ((array) $omit as $key => $value) {
                    if ($model->scalar((string) $key) === null && ! isset($computedFields[$key])) {
                        throw self::unknown($model, (string) $key, 'omit');
                    }
                    if ($value === true) {
                        $omitted[$key] = true;
                    } else {
                        unset($omitted[$key]);
                    }
                }
            }
            $scalars = array_values(array_filter(array_keys($model->scalars), fn (string $f) => ! isset($omitted[$f])));
            $computed = array_values(array_filter(array_keys($computedFields), fn (string $f) => ! isset($omitted[$f])));
            $keys = $scalars;
            if ($include !== null) {
                if (! is_array($include)) {
                    throw new PrismaClientValidationError('Argument `include` must be an object.');
                }
                foreach ($include as $key => $value) {
                    if ($value === false || $value === null) {
                        continue;
                    }
                    $key = (string) $key;
                    $keys[] = $key;
                    if ($key === '_count') {
                        $count = self::parseCount($model, $value);
                    } elseif ($model->relation($key) !== null) {
                        $relations[$key] = self::nestedArgs($value, $key);
                    } else {
                        throw self::unknown($model, $key, 'include');
                    }
                }
            }
            array_push($keys, ...$computed);
        }

        return new self($scalars, $relations, $count, $computed, array_values(array_unique($keys)));
    }

    /** @return array<string, mixed> */
    private static function nestedArgs(mixed $value, string $key): array
    {
        if ($value === true) {
            return [];
        }
        if (! is_array($value)) {
            throw new PrismaClientValidationError("Invalid value for `$key`: expected true or an object of query arguments.");
        }
        $allowed = ['select', 'include', 'omit', 'where', 'orderBy', 'take', 'skip', 'cursor', 'distinct'];
        foreach (array_keys($value) as $arg) {
            if (! in_array($arg, $allowed, true)) {
                throw new PrismaClientValidationError(sprintf('Unknown argument `%s` in the nested query of `%s`. Available: %s.', $arg, $key, implode(', ', $allowed)));
            }
        }

        return $value;
    }

    /** `_count: true` (every list relation) or `{ select: { posts: true | { where } } }`. @return array<string, array<string, mixed>> */
    private static function parseCount(ModelInfo $model, mixed $value): array
    {
        $count = [];
        if ($value === true) {
            foreach ($model->relations as $name => $relation) {
                if ($relation->isList()) {
                    $count[$name] = [];
                }
            }

            return $count;
        }
        $select = is_array($value) ? ($value['select'] ?? null) : null;
        if (! is_array($select)) {
            throw new PrismaClientValidationError('Argument `_count` must be true or `{ select: { relation: true } }`.');
        }
        foreach ($select as $name => $arg) {
            if ($arg === false || $arg === null) {
                continue;
            }
            $relation = $model->relation((string) $name);
            if ($relation === null || ! $relation->isList()) {
                throw new PrismaClientValidationError(sprintf('Unknown list relation `%s` in `_count` of model `%s`.', $name, $model->name));
            }
            $count[(string) $name] = is_array($arg) ? (array) ($arg['where'] ?? []) : [];
        }

        return $count;
    }

    private static function unknown(ModelInfo $model, string $key, string $argument): PrismaClientValidationError
    {
        return new PrismaClientValidationError(sprintf('Unknown field `%s` for %s statement on model `%s`. Available options: %s.', $key, $argument, $model->name, implode(', ', [...array_keys($model->scalars), ...array_keys($model->relations), '_count'])));
    }

    /**
     * Fields to read from the database: the output scalars, the identity, the join fields of the selected
     * relations and counts, the inputs of computed fields.
     *
     * @param array<string, array{needs: list<string>, compute: \Closure}> $computedFields
     * @return list<string>
     */
    public function columns(ModelInfo $model, array $computedFields = []): array
    {
        $fields = array_fill_keys($this->scalars, true);
        foreach ($model->identity() as $field) {
            $fields[$field] = true;
        }
        foreach (array_keys($this->relations) as $name) {
            foreach ($model->relations[$name]->thisFields() as $field) {
                $fields[$field] = true;
            }
        }
        foreach (array_keys($this->count ?? []) as $name) {
            foreach ($model->relations[$name]->thisFields() as $field) {
                $fields[$field] = true;
            }
        }
        foreach ($this->computed as $name) {
            foreach ($computedFields[$name]['needs'] ?? [] as $field) {
                if ($model->scalar($field) !== null) {
                    $fields[$field] = true;
                }
            }
        }

        return array_values(array_filter(array_keys($model->scalars), fn (string $f) => isset($fields[$f])));
    }
}
