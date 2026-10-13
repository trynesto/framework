<?php

declare(strict_types=1);

namespace Nesto\Prisma\Dmmf;

/**
 * A model or view (DMMF `datamodel.models[]` / `datamodel.views[]`): fields, `primaryKey` (compound @@id:
 * `['name' => ?string, 'fields' => list<string>]`), `uniqueFields` (field lists of compound uniques),
 * `uniqueIndexes` (`['name' => ?string, 'fields' => list<string>]`). `dbName` is the `@@map` table name.
 * `isIgnored` (@@ignore) is a Nesto addition: migrate still creates the table, the client skips it.
 */
final class Model
{
    /** @var array<string, Field> */
    private array $byName = [];

    /**
     * @param list<Field>                                          $fields
     * @param array{name: ?string, fields: list<string>}|null       $primaryKey
     * @param list<list<string>>                                    $uniqueFields
     * @param list<array{name: ?string, fields: list<string>}>      $uniqueIndexes
     */
    public function __construct(
        public readonly string $name,
        public readonly array $fields,
        public readonly ?string $dbName = null,
        public readonly ?array $primaryKey = null,
        public readonly array $uniqueFields = [],
        public readonly array $uniqueIndexes = [],
        public readonly ?string $documentation = null,
        public readonly bool $isView = false,
        public readonly bool $isIgnored = false,
        public readonly bool $isGenerated = false,
        public readonly ?string $schema = null,
    ) {
        foreach ($fields as $field) {
            $this->byName[$field->name] = $field;
        }
    }

    /** The table (or view) name: `@@map` or the model name. */
    public function tableName(): string
    {
        return $this->dbName ?? $this->name;
    }

    public function field(string $name): ?Field
    {
        return $this->byName[$name] ?? null;
    }

    /** Fields backed by a column (scalar, enum, unsupported), in declaration order. @return list<Field> */
    public function columnFields(): array
    {
        return array_values(array_filter($this->fields, fn (Field $f) => $f->isColumn()));
    }

    /** @return list<Field> */
    public function relationFields(): array
    {
        return array_values(array_filter($this->fields, fn (Field $f) => $f->isRelation()));
    }

    /** Field names of the primary key: the @id field or the @@id fields (empty when there is none). @return list<string> */
    public function primaryKeyFields(): array
    {
        if ($this->primaryKey !== null) {
            return $this->primaryKey['fields'];
        }
        foreach ($this->fields as $field) {
            if ($field->isId) {
                return [$field->name];
            }
        }

        return [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'dbName' => $this->dbName,
            'schema' => $this->schema,
            'fields' => array_map(fn (Field $f) => $f->toArray(), $this->fields),
            'primaryKey' => $this->primaryKey,
            'uniqueFields' => $this->uniqueFields,
            'uniqueIndexes' => $this->uniqueIndexes,
            'isGenerated' => $this->isGenerated,
        ] + ($this->documentation !== null ? ['documentation' => $this->documentation] : [])
          + ($this->isIgnored ? ['isIgnored' => true] : []);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, bool $isView = false): self
    {
        return new self(
            $data['name'],
            array_map(fn (array $f) => Field::fromArray($f), $data['fields']),
            $data['dbName'] ?? null,
            $data['primaryKey'] ?? null,
            $data['uniqueFields'] ?? [],
            $data['uniqueIndexes'] ?? [],
            $data['documentation'] ?? null,
            $isView,
            $data['isIgnored'] ?? false,
            $data['isGenerated'] ?? false,
            $data['schema'] ?? null,
        );
    }
}
