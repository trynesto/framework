<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

use Nesto\Orm\Schema\Column;
use Nesto\Orm\Schema\ForeignKey;
use Nesto\Orm\Schema\Index;
use Nesto\Orm\Schema\Table;
use Nesto\Orm\SqlExpression;
use Nesto\Prisma\Dmmf\Document;
use Nesto\Prisma\Dmmf\Field;
use Nesto\Prisma\Dmmf\FieldDefault;
use Nesto\Prisma\Dmmf\Index as DmmfIndex;
use Nesto\Prisma\Dmmf\ManyToMany;
use Nesto\Prisma\Dmmf\Model;
use Nesto\Prisma\Schema\NativeTypes;

/**
 * DMMF → the ORM's neutral schema model: the tables Prisma itself would create for the schema.
 *
 *   - tables: `@@map` or the model name; columns: `@map` or the field name, in field order
 *   - types: the provider's default native type of each scalar (TEXT / VARCHAR(191), TIMESTAMP(3) /
 *     DATETIME(3), DECIMAL(65,30), JSONB, ...) or the `@db.*` type; SERIAL / BIGSERIAL for autoincrement()
 *     on PostgreSQL; enums as native types (PostgreSQL CREATE TYPE, MySQL ENUM(...)); Unsupported("...") verbatim
 *   - defaults: literals, now() → CURRENT_TIMESTAMP (CURRENT_TIMESTAMP(3) for DATETIME(3) on MySQL),
 *     dbgenerated("...") as raw SQL; uuid() / cuid() / @updatedAt are filled by the client (no database default)
 *   - "{Table}_pkey" (PostgreSQL), "{Table}_{columns}_key" unique indexes, "{Table}_{columns}_idx" indexes,
 *     "{Table}_{columns}_fkey" foreign keys (ON DELETE RESTRICT / SET NULL for optional relations,
 *     ON UPDATE CASCADE by default); none with relationMode = "prisma"
 *   - implicit many-to-many: "_{Relation}" with columns A / B, a primary key "_{Relation}_AB_pkey"
 *     (PostgreSQL) or unique index "_{Relation}_AB_unique", an index "_{Relation}_B_index" and two cascading
 *     foreign keys
 *
 * Views are not managed by migrations (like Prisma).
 */
final class SchemaTranslator
{
    private const ACTIONS = ['Cascade' => 'CASCADE', 'Restrict' => 'RESTRICT', 'NoAction' => 'NO ACTION', 'SetNull' => 'SET NULL', 'SetDefault' => 'SET DEFAULT'];

    private string $provider;

    public function __construct(private readonly Document $document)
    {
        $this->provider = $document->requireDatasource()->provider;
    }

    public static function translate(Document $document): DatabaseSchema
    {
        return (new self($document))->schema();
    }

    public function schema(): DatabaseSchema
    {
        $tables = [];
        foreach ($this->document->models() as $model) {
            $table = $this->table($model);
            $tables[$table->name] = $table;
        }
        foreach ($this->document->manyToMany as $relation) {
            $table = $this->joinTable($relation);
            $tables[$table->name] = $table;
        }
        $enums = [];
        if ($this->provider === 'postgresql') {
            foreach ($this->document->enums() as $enum) {
                $enums[$enum->databaseName()] = $enum->databaseValues();
            }
        }

        $extensions = [];
        if ($this->provider === 'postgresql') {
            foreach ($this->document->requireDatasource()->extensions as $extension) {
                $extensions[$extension['dbName']] = ['schema' => $extension['schema'], 'version' => $extension['version']];
            }
        }

        return new DatabaseSchema($tables, $enums, $extensions);
    }

    private function table(Model $model): Table
    {
        $table = new Table($model->tableName());
        foreach ($model->columnFields() as $field) {
            $table->columns[$field->columnName()] = $this->column($field);
        }
        $table->primaryKey = array_map(fn (string $f) => $model->field($f)->columnName(), $model->primaryKeyFields());
        foreach ($table->primaryKey as $column) {
            $table->columns[$column]->primary = true;
            $table->columns[$column]->nullable = false;
        }
        foreach ($this->document->indexesOf($model->name) as $index) {
            $columns = array_map(fn (string $f) => $model->field($f)->columnName(), $index->fieldNames());
            if ($index->type === DmmfIndex::ID) {
                $table->primaryKeyName = $this->provider === 'postgresql' ? $index->dbName : null;
                continue;
            }
            $sorts = $lengths = [];
            foreach ($index->fields as $i => $indexField) {
                if ($indexField->sortOrder !== null) {
                    $sorts[$columns[$i]] = strtoupper($indexField->sortOrder);
                }
                if ($indexField->length !== null) {
                    $lengths[$columns[$i]] = $indexField->length;
                }
            }
            $table->indexes[] = new Index($columns, $index->type === DmmfIndex::UNIQUE, $index->dbName, true,
                sorts: $sorts, lengths: $lengths, using: $index->algorithm !== null ? strtoupper($index->algorithm) : null);
        }
        if ($this->document->requireDatasource()->usesForeignKeys()) {
            foreach ($model->relationFields() as $field) {
                if ($field->relationFromFields === []) {
                    continue;
                }
                $target = $this->document->model($field->type);
                $columns = array_map(fn (string $f) => $model->field($f)->columnName(), $field->relationFromFields);
                $optional = array_filter($field->relationFromFields, fn (string $f) => ! $model->field($f)->isRequired) !== [];
                $table->foreignKeys[] = new ForeignKey(
                    $columns,
                    $target->tableName(),
                    array_map(fn (string $f) => $target->field($f)->columnName(), $field->relationToFields),
                    self::ACTIONS[$field->relationOnDelete ?? ($optional ? 'SetNull' : 'Restrict')],
                    self::ACTIONS[$field->relationOnUpdate ?? 'Cascade'],
                    $field->relationFkName ?? \Nesto\Prisma\Schema\NamingConventions::foreignKeyName($table->name, $columns, $this->provider),
                    true,
                );
            }
        }

        return $table;
    }

    public function column(Field $field): Column
    {
        if ($field->kind === Field::ENUM) {
            $enum = $this->document->enum($field->type);
            $column = new Column($field->columnName(), 'enum');
            $column->values = $enum?->databaseValues() ?? [];
            if ($this->provider === 'postgresql') {
                $column->enumName = $enum?->databaseName() ?? $field->type;
            }
        } elseif ($field->kind === Field::UNSUPPORTED) {
            $column = new Column($field->columnName(), 'unsupported');
            $column->sqlType = $field->type;
        } else {
            [$native, $args] = $field->nativeType ?? NativeTypes::defaultFor($field->type, $this->provider);
            $column = new Column($field->columnName(), NativeTypes::abstractType($field->type, $native));
            $column->sqlType = NativeTypes::sql($native, $args, $this->provider);
            if ($field->isAutoIncrement() && $this->provider === 'postgresql') {
                $column->sqlType = match ($native) {
                    'BigInt' => 'BIGSERIAL',
                    'SmallInt' => 'SMALLSERIAL',
                    default => 'SERIAL',
                };
            }
        }
        $column->array = $field->isList && $this->provider === 'postgresql';
        $column->nullable = ! $field->isRequired || $field->isList;
        $column->autoIncrement = $field->isAutoIncrement();
        $this->applyDefault($column, $field);

        return $column;
    }

    private function applyDefault(Column $column, Field $field): void
    {
        if (! $field->hasDefaultValue || $field->isAutoIncrement()) {
            return;
        }
        $default = $field->default;
        if ($default instanceof FieldDefault) {
            $value = match ($default->name) {
                'now' => new SqlExpression($this->nowSql($column)),
                'dbgenerated' => $default->args !== [] ? new SqlExpression((string) $default->args[0]) : null,
                default => null, // uuid(), cuid(), nanoid(), ulid(): generated by the client
            };
            if ($value !== null) {
                $column->default = $value;
                $column->hasDefault = true;
            }

            return;
        }
        if (is_array($default)) {
            $column->default = new SqlExpression($this->arrayLiteral($column, $field, $default));
            $column->hasDefault = true;

            return;
        }
        if ($field->kind === Field::ENUM) {
            $default = $this->document->enum($field->type)?->value((string) $default)?->databaseValue() ?? $default;
        }
        if ($field->type === 'Decimal' || $field->type === 'Float') {
            $default = new SqlExpression((string) $default); // a number literal, unquoted
        }
        if ($field->type === 'Bytes' && is_string($default)) {
            $hex = bin2hex((string) base64_decode($default));
            $default = new SqlExpression(match ($this->provider) {
                'postgresql' => "'\\x$hex'",
                'mysql' => "(0x$hex)",
                default => "X'$hex'",
            });
        }
        $column->default = $default;
        $column->hasDefault = true;
    }

    /** CURRENT_TIMESTAMP with the column's fractional precision on MySQL (DATETIME(3) needs CURRENT_TIMESTAMP(3)). */
    private function nowSql(Column $column): string
    {
        if ($this->provider === 'mysql' && preg_match('/\((\d+)\)\s*$/', (string) $column->sqlType, $m) === 1 && (int) $m[1] > 0) {
            return 'CURRENT_TIMESTAMP(' . $m[1] . ')';
        }

        return 'CURRENT_TIMESTAMP';
    }

    /** PostgreSQL scalar list default: ARRAY['a', 'b']::TEXT[]. @param list<mixed> $values */
    private function arrayLiteral(Column $column, Field $field, array $values): string
    {
        $items = array_map(function (mixed $value) use ($field): string {
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
            if ($field->kind === Field::ENUM) {
                $value = $this->document->enum($field->type)?->value((string) $value)?->databaseValue() ?? $value;
            }

            return "'" . str_replace("'", "''", (string) $value) . "'";
        }, $values);
        $type = $column->enumName !== null ? '"' . $column->enumName . '"' : (string) $column->sqlType;

        return 'ARRAY[' . implode(', ', $items) . ']::' . $type . '[]';
    }

    private function joinTable(ManyToMany $relation): Table
    {
        $table = new Table($relation->table);
        $modelA = $this->document->model($relation->modelA);
        $modelB = $this->document->model($relation->modelB);
        foreach (['A' => $modelA, 'B' => $modelB] as $name => $model) {
            $id = $model->field($model->primaryKeyFields()[0]);
            $idColumn = $this->column($id);
            $column = new Column($name, $idColumn->type);
            $column->sqlType = match ($idColumn->sqlType) {
                'SERIAL' => 'INTEGER',
                'BIGSERIAL' => 'BIGINT',
                'SMALLSERIAL' => 'SMALLINT',
                default => $idColumn->sqlType,
            };
            $column->values = $idColumn->values;
            $column->enumName = $idColumn->enumName;
            $table->columns[$name] = $column;
        }
        $base = $relation->table;
        if ($this->provider === 'postgresql') {
            $table->primaryKey = ['A', 'B'];
            $table->primaryKeyName = $base . '_AB_pkey';
            $table->columns['A']->primary = $table->columns['B']->primary = true;
        } else {
            $table->indexes[] = new Index(['A', 'B'], true, $base . '_AB_unique', true);
        }
        $table->indexes[] = new Index(['B'], false, $base . '_B_index', true);
        if ($this->document->requireDatasource()->usesForeignKeys()) {
            foreach (['A' => $modelA, 'B' => $modelB] as $name => $model) {
                $id = $model->field($model->primaryKeyFields()[0]);
                $table->foreignKeys[] = new ForeignKey([$name], $model->tableName(), [$id->columnName()], 'CASCADE', 'CASCADE', $base . '_' . $name . '_fkey', true);
            }
        }

        return $table;
    }
}
