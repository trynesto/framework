<?php

declare(strict_types=1);

namespace Nesto\Prisma\Dmmf;

/** An `enum` block (DMMF `datamodel.enums[]`). `dbName` is the `@@map` name (the PostgreSQL type name). */
final class Enum
{
    /** @param list<EnumValue> $values */
    public function __construct(
        public readonly string $name,
        public readonly array $values,
        public readonly ?string $dbName = null,
        public readonly ?string $documentation = null,
    ) {}

    /** Name of the database type (PostgreSQL) — `@@map` or the enum name. */
    public function databaseName(): string
    {
        return $this->dbName ?? $this->name;
    }

    public function value(string $name): ?EnumValue
    {
        foreach ($this->values as $value) {
            if ($value->name === $name) {
                return $value;
            }
        }

        return null;
    }

    /** @return list<string> the values as stored in the database, in declaration order */
    public function databaseValues(): array
    {
        return array_map(fn (EnumValue $v) => $v->databaseValue(), $this->values);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'values' => array_map(fn (EnumValue $v) => $v->toArray(), $this->values),
            'dbName' => $this->dbName,
        ] + ($this->documentation !== null ? ['documentation' => $this->documentation] : []);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            array_map(fn (array $v) => EnumValue::fromArray($v), $data['values']),
            $data['dbName'] ?? null,
            $data['documentation'] ?? null,
        );
    }
}
