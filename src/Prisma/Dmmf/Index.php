<?php

declare(strict_types=1);

namespace Nesto\Prisma\Dmmf;

/**
 * An entry of DMMF `datamodel.indexes`: every id, unique and normal index of a model, with the database
 * name Prisma's naming conventions give it (`dbName`: "User_pkey", "User_email_key", "Post_title_idx", or
 * the `map:` argument). `name` is the client name of a compound id / unique (`@@unique(..., name: "x")`).
 * The primary key has no dbName on MySQL / SQLite (their primary keys are not named).
 */
final class Index
{
    public const ID = 'id';
    public const UNIQUE = 'unique';
    public const NORMAL = 'normal';
    public const FULLTEXT = 'fulltext';

    /** @param list<IndexField> $fields */
    public function __construct(
        public readonly string $model,
        public readonly string $type,
        public readonly bool $isDefinedOnField,
        public readonly array $fields,
        public readonly ?string $dbName = null,
        public readonly ?string $name = null,
        public readonly ?string $algorithm = null,
        public readonly ?bool $clustered = null,
        public readonly bool $mapped = false,
    ) {}

    /** @return list<string> */
    public function fieldNames(): array
    {
        return array_map(fn (IndexField $f) => $f->name, $this->fields);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'model' => $this->model,
            'type' => $this->type,
            'isDefinedOnField' => $this->isDefinedOnField,
            'name' => $this->name,
            'dbName' => $this->dbName,
            'algorithm' => $this->algorithm,
            'clustered' => $this->clustered,
            'fields' => array_map(fn (IndexField $f) => $f->toArray(), $this->fields),
        ], fn ($v) => $v !== null) + ($this->mapped ? ['mapped' => true] : []);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['model'],
            $data['type'],
            $data['isDefinedOnField'] ?? false,
            array_map(fn (array $f) => IndexField::fromArray($f), $data['fields']),
            $data['dbName'] ?? null,
            $data['name'] ?? null,
            $data['algorithm'] ?? null,
            $data['clustered'] ?? null,
            $data['mapped'] ?? false,
        );
    }
}
