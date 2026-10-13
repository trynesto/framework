<?php

declare(strict_types=1);

namespace Nesto\Prisma\Introspection;

use Nesto\Orm\Schema\Column;
use Nesto\Orm\Schema\ForeignKey;
use Nesto\Orm\Schema\Index;
use Nesto\Orm\Schema\Normalizer;
use Nesto\Orm\Schema\Table;
use Nesto\Orm\SqlExpression;
use Nesto\Prisma\Migrate\DatabaseSchema;
use Nesto\Prisma\Schema\Ast\Argument;
use Nesto\Prisma\Schema\Ast\ArgumentList;
use Nesto\Prisma\Schema\Ast\ArrayValue;
use Nesto\Prisma\Schema\Ast\Attribute;
use Nesto\Prisma\Schema\Ast\Block;
use Nesto\Prisma\Schema\Ast\ConstantValue;
use Nesto\Prisma\Schema\Ast\EmptyLine;
use Nesto\Prisma\Schema\Ast\EnumValue;
use Nesto\Prisma\Schema\Ast\Expression;
use Nesto\Prisma\Schema\Ast\Field;
use Nesto\Prisma\Schema\Ast\FieldType;
use Nesto\Prisma\Schema\Ast\FunctionCall;
use Nesto\Prisma\Schema\Ast\NumericValue;
use Nesto\Prisma\Schema\Ast\SchemaAst;
use Nesto\Prisma\Schema\Ast\StringValue;
use Nesto\Prisma\Schema\NamingConventions;
use Nesto\Prisma\Schema\Span;

/**
 * `prisma db pull`: a live database (read through the shared introspector) → Prisma models and enums.
 *
 *   - names that are not valid identifiers are sanitized and kept with `@map` / `@@map`
 *   - columns → scalar fields with `?` for NULL, `[]` for PostgreSQL arrays, `@db.*` for non-default types,
 *     `@default(...)` from the database default (autoincrement(), now(), literals, dbgenerated("..."))
 *   - primary keys → `@id` / `@@id`, unique indexes → `@unique` / `@@unique`, indexes → `@@index`, with
 *     `map:` when the name is not Prisma's default
 *   - foreign keys → relation fields on both sides (`User User @relation(fields: [userId], references: [id])`
 *     and `Post Post[]`, one-to-one when the key is unique), named relations for self / repeated relations,
 *     referential actions that differ from Prisma's defaults; `_AToB` join tables → implicit many-to-many
 *   - PostgreSQL enum types and MySQL ENUM columns (`{table}_{column}`) → enums
 *   - tables without a unique criteria get `@@ignore`; check constraints a documentation note
 *
 * Re-introspection: given the current schema, model / field / enum names, relation field names, `@updatedAt`,
 * client-side defaults (uuid(), cuid(), ...) and `///` documentation are kept.
 */
final class SchemaIntrospector
{
    private Span $span;
    /** @var array<string, string> table => model name */
    private array $modelNames = [];
    /** @var array<string, string> enum key (PG type / "table.column") => enum name */
    private array $enumNames = [];
    /** @var array<string, list<string>> enum name => database values */
    private array $enumValues = [];
    /** @var array<string, string> enum name => database name */
    private array $enumDbNames = [];
    /** @var array<string, Block> previous model blocks by table name */
    private array $previousModels = [];
    /** @var array<string, Block> previous enum blocks by database name */
    private array $previousEnums = [];

    public function __construct(
        private readonly DatabaseSchema $schema,
        private readonly string $platform,
        private readonly string $provider,
        private readonly string $datasourceName = 'db',
        private readonly ?SchemaAst $previous = null,
    ) {
        $this->span = Span::at(0, 0);
    }

    /** @return list<Block> models first (previous order, then new ones by name), then enums */
    public function blocks(): array
    {
        $this->indexPrevious();
        $tables = $this->schema->tables;
        $joinTables = [];
        foreach ($tables as $name => $table) {
            if ($this->isJoinTable($table)) {
                $joinTables[$name] = $table;
                unset($tables[$name]);
            }
        }
        $used = [];
        foreach ($tables as $name => $table) {
            $model = $this->previousModels[$name]->name ?? self::identifier($name, 'M');
            while (isset($used[$model])) {
                $model .= '_';
            }
            $used[$model] = true;
            $this->modelNames[$name] = $model;
        }
        $this->collectEnums($tables);

        // fields of every model, then relations (they need every model's names)
        $models = [];
        foreach ($tables as $name => $table) {
            $models[$name] = $this->modelFields($table);
        }
        $this->relations($tables, $joinTables, $models);

        $blocks = [];
        foreach ($models as $tableName => $model) {
            $blocks[$tableName] = $this->modelBlock($tables[$tableName], $model);
        }
        $order = [];
        foreach ($this->previous?->blocks(Block::MODEL) ?? [] as $block) {
            foreach ($blocks as $tableName => $built) {
                if ($built->name === $block->name) {
                    $order[] = $tableName;
                }
            }
        }
        $rest = array_values(array_diff(array_keys($blocks), $order));
        usort($rest, fn ($a, $b) => strcmp($this->modelNames[$a], $this->modelNames[$b]));
        $result = array_map(fn (string $t) => $blocks[$t], [...$order, ...$rest]);
        foreach ($this->enumBlocks() as $enum) {
            $result[] = $enum;
        }

        return $result;
    }

    // ---- previous schema --------------------------------------------------------------------------

    private function indexPrevious(): void
    {
        if ($this->previous === null) {
            return;
        }
        foreach ($this->previous->blocks(Block::MODEL) as $block) {
            $map = $block->attributes('map')[0] ?? null;
            $table = $map !== null && ($v = $map->arguments->get('name', 0)?->value) instanceof StringValue ? $v->value : $block->name;
            $this->previousModels[$table] = $block;
        }
        foreach ($this->previous->blocks(Block::ENUM) as $block) {
            $map = $block->attributes('map')[0] ?? null;
            $name = $map !== null && ($v = $map->arguments->get('name', 0)?->value) instanceof StringValue ? $v->value : $block->name;
            $this->previousEnums[$name] = $block;
        }
    }

    private function previousField(string $table, string $column): ?Field
    {
        $block = $this->previousModels[$table] ?? null;
        if ($block === null) {
            return null;
        }
        foreach ($block->fields() as $field) {
            $map = $field->attribute('map');
            $name = $map !== null && ($v = $map->arguments->get('name', 0)?->value) instanceof StringValue ? $v->value : $field->name;
            $isRelation = $field->attribute('relation') !== null || $this->isPreviousModel($field->type->name);
            if ($name === $column && ! $isRelation) {
                return $field;
            }
        }

        return null;
    }

    private function isPreviousModel(string $type): bool
    {
        foreach ($this->previousModels as $block) {
            if ($block->name === $type) {
                return true;
            }
        }

        return false;
    }

    // ---- enums ------------------------------------------------------------------------------------

    /** @param array<string, Table> $tables */
    private function collectEnums(array $tables): void
    {
        if ($this->platform === 'pgsql') {
            foreach ($this->schema->enums as $type => $values) {
                $this->registerEnum($type, $type, $values);
            }

            return;
        }
        if ($this->platform !== 'mysql' && $this->platform !== 'mariadb') {
            return;
        }
        foreach ($tables as $tableName => $table) {
            foreach ($table->columns as $column) {
                if ($column->type === 'enum') {
                    $this->registerEnum($tableName . '.' . $column->name, $tableName . '_' . $column->name, array_map('strval', $column->values));
                }
            }
        }
    }

    /** @param list<string> $values */
    private function registerEnum(string $key, string $databaseName, array $values): void
    {
        $name = $this->previousEnums[$databaseName]->name ?? self::identifier($databaseName, 'E');
        while (in_array($name, $this->enumNames, true) || in_array($name, $this->modelNames, true)) {
            $name .= '_';
        }
        $this->enumNames[$key] = $name;
        $this->enumValues[$name] = $values;
        $this->enumDbNames[$name] = $databaseName;
    }

    /** @return list<Block> */
    private function enumBlocks(): array
    {
        $blocks = [];
        foreach ($this->enumValues as $name => $values) {
            $databaseName = $this->enumDbNames[$name];
            $previous = $this->previousEnums[$databaseName] ?? null;
            $members = [];
            foreach ($values as $value) {
                $valueName = self::identifier($value, 'VALUE_');
                $previousValue = null;
                foreach ($previous?->values() ?? [] as $candidate) {
                    $map = $candidate->attribute('map');
                    $db = $map !== null && ($v = $map->arguments->get('name', 0)?->value) instanceof StringValue ? $v->value : $candidate->name;
                    if ($db === $value) {
                        $previousValue = $candidate;
                    }
                }
                $valueName = $previousValue->name ?? $valueName;
                $attributes = $valueName !== $value ? [$this->attribute('map', [$this->string($value)])] : [];
                $members[] = new EnumValue($valueName, $attributes, $this->span, $previousValue?->documentation);
            }
            if ($name !== $databaseName) {
                $members[] = new EmptyLine($this->span);
                $members[] = $this->attribute('map', [$this->string($databaseName)], true);
            }
            $blocks[] = new Block(Block::ENUM, $name, $this->span, $members, $this->span, $previous?->documentation);
        }

        return $blocks;
    }

    // ---- models -----------------------------------------------------------------------------------

    /** @return array{fields: array<string, array{name: string, type: FieldType, attributes: list<Attribute>, doc: ?string}>, block: list<Attribute>} */
    private function modelFields(Table $table): array
    {
        $fields = [];
        $blockAttributes = [];
        $model = $this->modelNames[$table->name];
        $singlePk = count($table->primaryKey) === 1 ? $table->primaryKey[0] : null;
        $uniqueSingle = [];
        foreach ($table->indexes as $index) {
            if ($index->unique && count($index->columns) === 1 && $index->where === null && ! $index->implicit) {
                $uniqueSingle[$index->columns[0]] ??= $index;
            }
        }
        foreach ($table->columns as $column) {
            $previous = $this->previousField($table->name, $column->name);
            $fieldName = $previous->name ?? self::identifier($column->name, 'field_');
            $attributes = [];
            if ($column->enumName !== null && $this->platform === 'pgsql' || $column->type === 'enum' && $this->platform !== 'pgsql' && $this->platform !== 'sqlite') {
                $enum = $this->enumNames[$this->platform === 'pgsql' ? (string) $column->enumName : $table->name . '.' . $column->name] ?? null;
                $mapped = $enum !== null ? ['type' => $enum, 'native' => null, 'unsupported' => null] : ['type' => 'Unsupported', 'native' => null, 'unsupported' => (string) $column->rawType];
                $isEnum = $enum !== null;
            } else {
                $mapped = TypeMapper::map($column, $this->platform);
                $isEnum = false;
            }
            $list = $column->array;
            $type = new FieldType($mapped['type'], $column->nullable && ! $list, $list, $this->span, $mapped['unsupported']);
            if ($singlePk === $column->name) {
                $pkName = $table->primaryKeyName;
                $args = $pkName !== null && $this->provider === 'postgresql' && $pkName !== NamingConventions::primaryKeyName($table->name, $this->provider) ? [$this->named('map', $this->string($pkName))] : [];
                $attributes[] = $this->attribute('id', $args);
            }
            $default = $this->defaultAttribute($column, $mapped['type'], $isEnum, $previous);
            if ($default !== null) {
                $attributes[] = $default;
            }
            if ($previous?->attribute('updatedAt') !== null && $mapped['type'] === 'DateTime') {
                $attributes[] = $this->attribute('updatedAt');
            }
            if (isset($uniqueSingle[$column->name]) && $singlePk !== $column->name) {
                $index = $uniqueSingle[$column->name];
                $args = [];
                if ($index->name !== null && $index->name !== NamingConventions::uniqueIndexName($table->name, [$column->name], $this->provider)) {
                    $args[] = $this->named('map', $this->string($index->name));
                }
                $attributes[] = $this->attribute('unique', $args);
            }
            if ($fieldName !== $column->name) {
                $attributes[] = $this->attribute('map', [$this->string($column->name)]);
            }
            if ($mapped['native'] !== null) {
                [$native, $nativeArgs] = $mapped['native'];
                $attributes[] = new Attribute($this->datasourceName . '.' . $native, new ArgumentList(array_map(fn (string $a) => new Argument(null, new NumericValue($a, $this->span), $this->span), $nativeArgs)), false, $this->span, $this->span, $nativeArgs !== []);
            }
            if ($previous?->attribute('ignore') !== null) {
                $attributes[] = $this->attribute('ignore');
            }
            $fields[$column->name] = ['name' => $fieldName, 'type' => $type, 'attributes' => $attributes, 'doc' => $previous?->documentation];
        }
        if (count($table->primaryKey) > 1) {
            $args = [new Argument(null, $this->fieldList($table, $table->primaryKey, $fields), $this->span)];
            if ($table->primaryKeyName !== null && $this->provider === 'postgresql' && $table->primaryKeyName !== NamingConventions::primaryKeyName($table->name, $this->provider)) {
                $args[] = $this->named('map', $this->string($table->primaryKeyName));
            }
            $blockAttributes[] = $this->attribute('id', $args, true);
        }
        foreach ($table->indexes as $index) {
            if ($index->implicit || $index->where !== null || $index->columns === []) {
                continue;
            }
            if ($index->unique && count($index->columns) === 1 && ($uniqueSingle[$index->columns[0]] ?? null) === $index) {
                continue;
            }
            $default = $index->unique
                ? NamingConventions::uniqueIndexName($table->name, $index->columns, $this->provider)
                : NamingConventions::normalIndexName($table->name, $index->columns, $this->provider);
            $args = [new Argument(null, $this->fieldList($table, $index->columns, $fields), $this->span)];
            if ($index->name !== null && $index->name !== $default) {
                $args[] = $this->named('map', $this->string($index->name));
            }
            $blockAttributes[] = $this->attribute($index->unique ? 'unique' : 'index', $args, true);
        }

        return ['fields' => $fields, 'block' => $blockAttributes, 'relations' => []];
    }

    /** @param array<string, array<string, mixed>> $fields */
    private function fieldList(Table $table, array $columns, array $fields): ArrayValue
    {
        return new ArrayValue(array_map(fn (string $c) => new ConstantValue($fields[$c]['name'] ?? $c, $this->span), $columns), $this->span);
    }

    private function defaultAttribute(Column $column, string $type, bool $isEnum, ?Field $previous): ?Attribute
    {
        if ($column->autoIncrement) {
            return $this->attribute('default', [new FunctionCall('autoincrement', new ArgumentList(), $this->span)]);
        }
        if (! $column->hasDefault || $column->default === null) {
            // client-side defaults live only in the schema: keep them
            $previousDefault = $previous?->attribute('default');
            $value = $previousDefault?->arguments->get('value', 0)?->value;
            if ($value instanceof FunctionCall && in_array($value->name, ['uuid', 'cuid', 'nanoid', 'ulid', 'dbgenerated'], true) && ! ($value->name === 'dbgenerated' && ! $value->arguments->isEmpty())) {
                return $previousDefault;
            }

            return null;
        }
        $default = $column->default;
        if ($default instanceof SqlExpression && preg_match("/^'((?:[^']|'')*)'::[\"A-Za-z_ ]+(\\[\\])?$/s", trim($default->sql), $m) === 1) {
            $default = str_replace("''", "'", $m[1]); // a cast literal: 'USER'::"Role"
        }
        if ($default instanceof SqlExpression) {
            $normalized = Normalizer::expression($default->sql);
            if ($type === 'DateTime' && preg_match('/^current_timestamp(\(\d*\))?$/', $normalized) === 1) {
                return $this->attribute('default', [new FunctionCall('now', new ArgumentList(), $this->span)]);
            }
            $previousDefault = $previous?->attribute('default');
            $value = $previousDefault?->arguments->get('value', 0)?->value;
            if ($value instanceof FunctionCall && $value->name === 'dbgenerated') {
                return $previousDefault;
            }

            return $this->attribute('default', [new FunctionCall('dbgenerated', new ArgumentList([new Argument(null, $this->string($default->sql), $this->span)]), $this->span)]);
        }
        if ($isEnum) {
            $enum = $type;
            $value = (string) $default;
            $name = self::identifier($value, 'VALUE_');
            foreach ($this->previousEnums as $block) {
                if ($block->name === $enum) {
                    foreach ($block->values() as $candidate) {
                        $map = $candidate->attribute('map');
                        $db = $map !== null && ($v = $map->arguments->get('name', 0)?->value) instanceof StringValue ? $v->value : $candidate->name;
                        if ($db === $value) {
                            $name = $candidate->name;
                        }
                    }
                }
            }

            return $this->attribute('default', [new ConstantValue($name, $this->span)]);
        }
        $literal = match ($type) {
            'Boolean' => new ConstantValue(in_array(strtolower((string) (is_bool($default) ? ($default ? 'true' : 'false') : $default)), ['1', 'true', 't'], true) ? 'true' : 'false', $this->span),
            'Int', 'BigInt', 'Float', 'Decimal' => is_numeric($default) ? new NumericValue(Normalizer::number((string) $default), $this->span) : null,
            'String', 'DateTime', 'Json' => $this->string((string) (is_bool($default) ? ($default ? 'true' : 'false') : $default)),
            default => null,
        };
        if ($literal === null) {
            return $this->attribute('default', [new FunctionCall('dbgenerated', new ArgumentList([new Argument(null, $this->string(is_scalar($default) ? (string) $default : ''), $this->span)]), $this->span)]);
        }

        return $this->attribute('default', [$literal]);
    }

    // ---- relations --------------------------------------------------------------------------------

    /**
     * @param array<string, Table> $tables
     * @param array<string, Table> $joinTables
     * @param array<string, array<string, mixed>> $models
     */
    private function relations(array $tables, array $joinTables, array &$models): void
    {
        // how many relations connect each pair of models (named relations are needed when > 1)
        $pairs = [];
        $key = fn (string $a, string $b) => implode("\0", [min($a, $b), max($a, $b)]);
        foreach ($tables as $name => $table) {
            foreach ($table->foreignKeys as $fk) {
                if (isset($tables[$fk->referencedTable])) {
                    $pairs[$key($name, $fk->referencedTable)] = ($pairs[$key($name, $fk->referencedTable)] ?? 0) + 1;
                }
            }
        }
        foreach ($joinTables as $join) {
            [$a, $b] = $this->joinTargets($join);
            $pairs[$key($a, $b)] = ($pairs[$key($a, $b)] ?? 0) + 1;
        }

        foreach ($tables as $name => $table) {
            foreach ($table->foreignKeys as $fk) {
                if (! isset($tables[$fk->referencedTable])) {
                    continue;
                }
                $target = $fk->referencedTable;
                $model = $this->modelNames[$name];
                $targetModel = $this->modelNames[$target];
                $self = $name === $target;
                $ambiguous = $self || ($pairs[$key($name, $target)] ?? 0) > 1;
                $relationName = $ambiguous
                    ? ($self && ($pairs[$key($name, $target)] ?? 0) === 1 ? NamingConventions::relationName($model, $targetModel) : $model . '_' . implode('_', $fk->columns) . 'To' . $targetModel)
                    : null;
                $previousRelation = $this->previousRelation($name, $fk);
                if ($previousRelation !== null) {
                    $relationName = $previousRelation['relationName'] ?? $relationName;
                }
                $optional = false;
                foreach ($fk->columns as $columnName) {
                    $optional = $optional || ($table->column($columnName)?->nullable ?? false);
                }
                $unique = $this->isUnique($table, $fk->columns);
                $forwardName = $previousRelation['field'] ?? ($ambiguous && ! ($self && $relationName === NamingConventions::relationName($model, $targetModel)) ? $targetModel . '_' . $relationName : $targetModel);
                $backName = $previousRelation['back'] ?? ($self ? 'other_' . $model : ($ambiguous ? $model . '_' . $relationName : $model));
                $forwardName = $this->uniqueFieldName($models[$name], $forwardName);
                $args = [];
                if ($relationName !== null) {
                    $args[] = new Argument(null, $this->string($relationName), $this->span);
                }
                $args[] = $this->named('fields', new ArrayValue(array_map(fn (string $c) => new ConstantValue($models[$name]['fields'][$c]['name'] ?? $c, $this->span), $fk->columns), $this->span));
                $args[] = $this->named('references', new ArrayValue(array_map(fn (string $c) => new ConstantValue($models[$target]['fields'][$c]['name'] ?? $c, $this->span), $fk->referencedColumns), $this->span));
                $onDelete = self::action($fk->onDelete);
                $onUpdate = self::action($fk->onUpdate);
                if ($onDelete !== ($optional ? 'SetNull' : 'Restrict')) {
                    $args[] = $this->named('onDelete', new ConstantValue($onDelete, $this->span));
                }
                if ($onUpdate !== 'Cascade') {
                    $args[] = $this->named('onUpdate', new ConstantValue($onUpdate, $this->span));
                }
                if ($fk->name !== null && $fk->name !== NamingConventions::foreignKeyName($name, $fk->columns, $this->provider)) {
                    $args[] = $this->named('map', $this->string($fk->name));
                }
                $models[$name]['relations'][] = ['name' => $forwardName, 'type' => new FieldType($targetModel, $optional, false, $this->span), 'attributes' => [$this->attribute('relation', $args)]];
                $backName = $this->uniqueFieldName($models[$target], $backName);
                $models[$target]['relations'][] = [
                    'name' => $backName,
                    'type' => new FieldType($model, $unique, ! $unique, $this->span),
                    'attributes' => $relationName !== null ? [$this->attribute('relation', [new Argument(null, $this->string($relationName), $this->span)])] : [],
                ];
            }
        }
        foreach ($joinTables as $joinName => $join) {
            [$a, $b] = $this->joinTargets($join);
            $relationName = substr($joinName, 1);
            $modelA = $this->modelNames[$a];
            $modelB = $this->modelNames[$b];
            $explicit = $relationName !== NamingConventions::relationName($modelA, $modelB) || $a === $b || ($pairs[$key($a, $b)] ?? 0) > 1;
            $attributes = $explicit ? [$this->attribute('relation', [new Argument(null, $this->string($relationName), $this->span)])] : [];
            $previousName = function (string $table, string $type) use ($relationName, $explicit): ?string {
                foreach (($this->previousModels[$table] ?? null)?->fields() ?? [] as $field) {
                    $name = $field->attribute('relation')?->arguments->get('name', 0)?->value;
                    $name = $name instanceof StringValue ? $name->value : null;
                    if ($field->type->list && $field->type->name === $type && ($name === $relationName || ($name === null && ! $explicit))) {
                        return $field->name;
                    }
                }

                return null;
            };
            $nameA = $previousName($a, $modelB) ?? ($a === $b ? $modelB . '_A' : $modelB);
            $nameB = $previousName($b, $modelA) ?? ($a === $b ? $modelA . '_B' : $modelA);
            $models[$a]['relations'][] = ['name' => $this->uniqueFieldName($models[$a], $nameA), 'type' => new FieldType($modelB, false, true, $this->span), 'attributes' => $attributes];
            $models[$b]['relations'][] = ['name' => $this->uniqueFieldName($models[$b], $nameB), 'type' => new FieldType($modelA, false, true, $this->span), 'attributes' => $attributes];
        }
    }

    /** The previous schema's names for the relation of this foreign key. @return array{field: string, back: ?string, relationName: ?string}|null */
    private function previousRelation(string $table, ForeignKey $fk): ?array
    {
        $block = $this->previousModels[$table] ?? null;
        $targetBlock = $this->previousModels[$fk->referencedTable] ?? null;
        if ($block === null || $targetBlock === null) {
            return null;
        }
        $columnsToFields = [];
        foreach ($block->fields() as $field) {
            $map = $field->attribute('map');
            $columnsToFields[$map !== null && ($v = $map->arguments->get('name', 0)?->value) instanceof StringValue ? $v->value : $field->name] = $field->name;
        }
        $fieldNames = array_map(fn (string $c) => $columnsToFields[$c] ?? $c, $fk->columns);
        foreach ($block->fields() as $field) {
            $relation = $field->attribute('relation');
            $fields = $relation?->arguments->get('fields')?->value;
            if ($field->type->name !== $targetBlock->name || ! $fields instanceof ArrayValue) {
                continue;
            }
            if (array_map(fn (Expression $e) => $e->toSource(), $fields->items) !== $fieldNames) {
                continue;
            }
            $nameValue = $relation->arguments->get('name', 0)?->value;
            $relationName = $nameValue instanceof StringValue ? $nameValue->value : null;
            $back = null;
            foreach ($targetBlock->fields() as $candidate) {
                $candidateRelation = $candidate->attribute('relation')?->arguments->get('name', 0)?->value;
                $candidateName = $candidateRelation instanceof StringValue ? $candidateRelation->value : null;
                if ($candidate->type->name === $block->name && $candidateName === $relationName && $candidate !== $field) {
                    $back = $candidate->name;
                }
            }

            return ['field' => $field->name, 'back' => $back, 'relationName' => $relationName];
        }

        return null;
    }

    /** @param array<string, mixed> $model */
    private function uniqueFieldName(array $model, string $name): string
    {
        $taken = array_merge(array_map(fn ($f) => $f['name'], $model['fields']), array_map(fn ($f) => $f['name'], $model['relations']));
        $candidate = $name;
        while (in_array($candidate, $taken, true)) {
            $candidate .= '_';
        }

        return $candidate;
    }

    /** @param list<string> $columns */
    private function isUnique(Table $table, array $columns): bool
    {
        if (Normalizer::sameColumns($table->primaryKey, $columns)) {
            return true;
        }
        foreach ($table->indexes as $index) {
            if ($index->unique && $index->where === null && Normalizer::sameColumns($index->columns, $columns)) {
                return true;
            }
        }

        return false;
    }

    /** `_AToB` with columns A / B referencing two tables, unique (A, B) and nothing else. */
    private function isJoinTable(Table $table): bool
    {
        if (! str_starts_with($table->name, '_') || array_keys($table->columns) !== ['A', 'B'] || count($table->foreignKeys) !== 2) {
            return false;
        }
        $columns = array_map(fn (ForeignKey $fk) => $fk->columns, $table->foreignKeys);
        sort($columns);
        if ($columns !== [['A'], ['B']]) {
            return false;
        }
        if (Normalizer::sameColumns($table->primaryKey, ['A', 'B'])) {
            return true;
        }
        foreach ($table->indexes as $index) {
            if ($index->unique && Normalizer::sameColumns($index->columns, ['A', 'B'])) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: string, 1: string} the tables referenced by A and B */
    private function joinTargets(Table $join): array
    {
        $targets = [];
        foreach ($join->foreignKeys as $fk) {
            $targets[$fk->columns[0]] = $fk->referencedTable;
        }

        return [$targets['A'], $targets['B']];
    }

    private static function action(?string $action): string
    {
        return match (strtoupper(trim((string) $action))) {
            'CASCADE' => 'Cascade',
            'RESTRICT' => 'Restrict',
            'SET NULL' => 'SetNull',
            'SET DEFAULT' => 'SetDefault',
            default => 'NoAction',
        };
    }

    // ---- blocks -----------------------------------------------------------------------------------

    /** @param array<string, mixed> $model */
    private function modelBlock(Table $table, array $model): Block
    {
        $name = $this->modelNames[$table->name];
        $previous = $this->previousModels[$table->name] ?? null;
        $members = [];
        foreach ($model['fields'] as $field) {
            $members[] = new Field($field['name'], $this->span, $field['type'], $field['attributes'], $this->span, $field['doc']);
        }
        foreach ($model['relations'] as $relation) {
            $doc = null;
            foreach ($previous?->fields() ?? [] as $candidate) {
                if ($candidate->name === $relation['name']) {
                    $doc = $candidate->documentation;
                }
            }
            $members[] = new Field($relation['name'], $this->span, $relation['type'], $relation['attributes'], $this->span, $doc);
        }
        $blockAttributes = $model['block'];
        if ($name !== $table->name) {
            $blockAttributes[] = $this->attribute('map', [$this->string($table->name)], true);
        }
        $documentation = $previous?->documentation;
        if (! $this->hasUniqueCriteria($table)) {
            $blockAttributes[] = $this->attribute('ignore', [], true);
            $documentation = trim(($documentation !== null ? $documentation . "\n" : '') . 'The underlying table does not contain a valid unique identifier and can therefore currently not be handled by Prisma Client.');
        }
        if ($table->checks !== [] && ($documentation === null || ! str_contains($documentation, 'check constraints'))) {
            $documentation = trim(($documentation !== null ? $documentation . "\n" : '') . 'This table contains check constraints and requires additional setup for migrations. Visit https://pris.ly/d/check-constraints for more info.');
        }
        if ($blockAttributes !== []) {
            $members[] = new EmptyLine($this->span);
            array_push($members, ...$blockAttributes);
        }

        return new Block(Block::MODEL, $name, $this->span, $members, $this->span, $documentation);
    }

    private function hasUniqueCriteria(Table $table): bool
    {
        if ($table->primaryKey !== []) {
            return true;
        }
        foreach ($table->indexes as $index) {
            if ($index->unique && $index->where === null && $index->columns !== []) {
                $required = array_filter($index->columns, fn (string $c) => ! ($table->column($c)?->nullable ?? true));
                if (count($required) === count($index->columns)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<Expression|Argument> $arguments */
    private function attribute(string $name, array $arguments = [], bool $block = false): Attribute
    {
        $args = array_map(fn ($a) => $a instanceof Argument ? $a : new Argument(null, $a, $this->span), $arguments);

        return new Attribute($name, new ArgumentList($args), $block, $this->span, $this->span, $args !== []);
    }

    private function named(string $name, Expression $value): Argument
    {
        return new Argument($name, $value, $this->span);
    }

    private function string(string $value): StringValue
    {
        return new StringValue($value, StringValue::quote($value), $this->span);
    }

    /** A valid PSL identifier for a database name (invalid characters → "_", a prefix when it does not start with a letter). */
    public static function identifier(string $name, string $prefix): string
    {
        $clean = (string) preg_replace('/[^A-Za-z0-9_]/', '_', $name);
        if ($clean === '' || ! ctype_alpha($clean[0])) {
            $clean = $prefix . $clean;
        }

        return $clean;
    }
}
