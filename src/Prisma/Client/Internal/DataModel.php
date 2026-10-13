<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Internal;

use Nesto\Prisma\Dmmf\Document;
use Nesto\Prisma\Dmmf\Field;
use Nesto\Prisma\Dmmf\Model;
use Nesto\Prisma\Schema\NamingConventions;

/**
 * The client's view of a Dmmf\Document: ModelInfo per usable model (models and views, not @@ignore'd),
 * built once and immutable — shared by every request of a worker.
 *
 * @internal
 */
final class DataModel
{
    /** @var array<string, ModelInfo> */
    private array $models = [];
    /** @var array<string, string> delegate name => model name */
    private array $delegates = [];
    /** @var array<string, string> unique constraint / index database name => "Model.field1,field2" */
    private array $constraints = [];
    /** @var list<array{name: string, model: string, relation: string, fields: list<string>, target: string}> */
    private array $foreignKeys = [];

    public readonly string $relationMode;

    public function __construct(public readonly Document $document)
    {
        $this->relationMode = $document->datasource?->relationMode ?? 'foreignKeys';
        foreach ([...$document->models(), ...$document->views()] as $model) {
            if ($model->isIgnored) {
                continue;
            }
            $info = $this->build($model);
            $this->models[$model->name] = $info;
            $this->delegates[$info->delegateName()] = $model->name;
        }
        if ($this->relationMode === 'foreignKeys' && $document->datasource !== null) {
            foreach ($document->models() as $model) {
                foreach ($model->relationFields() as $field) {
                    if ($field->relationFromFields === []) {
                        continue;
                    }
                    $columns = array_map(fn (string $f) => $model->field($f)?->columnName() ?? $f, $field->relationFromFields);
                    $this->foreignKeys[] = [
                        'name' => $field->relationFkName ?? NamingConventions::foreignKeyName($model->tableName(), $columns, $document->datasource->provider),
                        'model' => $model->name,
                        'relation' => $field->name,
                        'fields' => $field->relationFromFields,
                        'target' => $field->type,
                    ];
                }
            }
        }
        foreach ($document->indexes as $index) {
            if ($index->dbName !== null && in_array($index->type, ['unique', 'id'], true)) {
                $this->constraints[$index->dbName] = $index->model . '.' . implode(',', array_map(fn ($f) => $f->name, $index->fields));
            }
        }
    }

    public function model(string $name): ModelInfo
    {
        return $this->models[$name] ?? throw new \Nesto\Prisma\Client\Errors\PrismaClientValidationError("Unknown model `$name`.");
    }

    public function has(string $name): bool
    {
        return isset($this->models[$name]);
    }

    /** @return array<string, ModelInfo> */
    public function models(): array
    {
        return $this->models;
    }

    /** Model name of a delegate property ("user" → "User"). */
    public function modelOfDelegate(string $delegate): ?string
    {
        return $this->delegates[$delegate] ?? null;
    }

    /** Model and fields of a unique constraint by its database name. @return array{0: string, 1: list<string>}|null */
    public function constraint(string $name): ?array
    {
        if (! isset($this->constraints[$name])) {
            return null;
        }
        [$model, $fields] = explode('.', $this->constraints[$name], 2);

        return [$model, explode(',', $fields)];
    }

    /**
     * The database foreign keys (relationMode "foreignKeys") of $model, or with $referencing those of other models
     * pointing at $model.
     *
     * @return list<array{name: string, model: string, relation: string, fields: list<string>, target: string}>
     */
    public function foreignKeys(string $model, bool $referencing = false): array
    {
        return array_values(array_filter($this->foreignKeys, fn (array $fk) => ($referencing ? $fk['target'] : $fk['model']) === $model));
    }

    private function build(Model $model): ModelInfo
    {
        $scalars = [];
        $relations = [];
        $uniqueKeys = [];
        $compound = [];
        foreach ($model->fields as $field) {
            if ($field->isIgnored || $field->kind === Field::UNSUPPORTED) {
                continue;
            }
            if ($field->isRelation()) {
                $relation = $this->relation($model, $field);
                if ($relation !== null) {
                    $relations[$field->name] = $relation;
                }
                continue;
            }
            $scalars[$field->name] = $field;
            if ($field->isId || $field->isUnique) {
                $uniqueKeys[$field->name] = [$field->name];
            }
        }
        if ($model->primaryKey !== null && count($model->primaryKey['fields']) > 1) {
            $name = $model->primaryKey['name'] ?? implode('_', $model->primaryKey['fields']);
            $uniqueKeys[$name] = $compound[$name] = $model->primaryKey['fields'];
        }
        foreach ($model->uniqueIndexes as $unique) {
            if (count($unique['fields']) === 1) {
                $uniqueKeys[$unique['fields'][0]] = $unique['fields'];
                continue;
            }
            $name = $unique['name'] ?? implode('_', $unique['fields']);
            $uniqueKeys[$name] = $compound[$name] = $unique['fields'];
        }
        $primaryKey = array_values(array_filter($model->primaryKeyFields(), fn (string $f) => isset($scalars[$f])));

        return new ModelInfo($model, $model->name, $model->tableName(), $scalars, $relations, $primaryKey, $uniqueKeys, $compound);
    }

    private function relation(Model $model, Field $field): ?RelationInfo
    {
        $target = $this->document->model($field->type);
        if ($target === null || $target->isIgnored) {
            return null;
        }
        $opposite = $this->document->oppositeField($model, $field);
        if ($field->relationFromFields !== []) {
            $pairs = array_map(null, $field->relationFromFields, $field->relationToFields);
            $required = $this->fieldsRequired($model, $field->relationFromFields);

            return new RelationInfo($model->name, $field, RelationInfo::OWNER, $target->name, $pairs, $opposite?->name,
                onDelete: $field->relationOnDelete ?? ($required ? 'Restrict' : 'SetNull'), fkRequired: $required);
        }
        if ($opposite === null) {
            return null;
        }
        if ($field->isList && $opposite->isList) {
            $m2m = $this->document->manyToManyOf((string) $field->relationName);
            if ($m2m === null) {
                return null;
            }
            $thisIsA = $m2m->modelA === $model->name && $m2m->fieldA === $field->name;
            $thisId = $model->primaryKeyFields()[0] ?? 'id';
            $otherId = $target->primaryKeyFields()[0] ?? 'id';

            return new RelationInfo($model->name, $field, RelationInfo::M2M, $target->name, [[$thisId, $otherId]], $opposite->name,
                $m2m->table, $thisIsA ? 'A' : 'B', $thisIsA ? 'B' : 'A', 'Cascade');
        }
        // the target holds the foreign key: this.toFields = target.fromFields
        $pairs = array_map(null, $opposite->relationToFields, $opposite->relationFromFields);
        $required = $this->fieldsRequired($target, $opposite->relationFromFields);

        return new RelationInfo($model->name, $field, $field->isList ? RelationInfo::MANY : RelationInfo::BACK, $target->name, $pairs, $opposite->name,
            onDelete: $opposite->relationOnDelete ?? ($required ? 'Restrict' : 'SetNull'), fkRequired: $required);
    }

    /** @param list<string> $fields */
    private function fieldsRequired(Model $model, array $fields): bool
    {
        foreach ($fields as $name) {
            if ($model->field($name)?->isRequired === false) {
                return false;
            }
        }

        return true;
    }
}
