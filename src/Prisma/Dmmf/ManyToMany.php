<?php

declare(strict_types=1);

namespace Nesto\Prisma\Dmmf;

/**
 * An implicit many-to-many relation (two list relation fields, no relation model) and its join table, named
 * like Prisma does: table "_{relationName}" ("_CategoryToPost"), column "A" referencing the id of the model
 * whose name sorts first ($modelA), column "B" referencing the other ($modelB).
 */
final class ManyToMany
{
    public function __construct(
        public readonly string $relationName,
        public readonly string $table,
        public readonly string $modelA,
        public readonly string $fieldA,
        public readonly string $modelB,
        public readonly string $fieldB,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'relationName' => $this->relationName,
            'table' => $this->table,
            'modelA' => $this->modelA,
            'fieldA' => $this->fieldA,
            'modelB' => $this->modelB,
            'fieldB' => $this->fieldB,
        ];
    }

    /** @param array<string, string> $data */
    public static function fromArray(array $data): self
    {
        return new self($data['relationName'], $data['table'], $data['modelA'], $data['fieldA'], $data['modelB'], $data['fieldB']);
    }
}
