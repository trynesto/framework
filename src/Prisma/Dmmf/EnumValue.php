<?php

declare(strict_types=1);

namespace Nesto\Prisma\Dmmf;

/** One enum value: its name in the schema / client and in the database (`@map`). */
final class EnumValue
{
    public function __construct(public readonly string $name, public readonly ?string $dbName = null, public readonly ?string $documentation = null) {}

    /** The value stored in the database. */
    public function databaseValue(): string
    {
        return $this->dbName ?? $this->name;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['name' => $this->name, 'dbName' => $this->dbName] + ($this->documentation !== null ? ['documentation' => $this->documentation] : []);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['dbName'] ?? null, $data['documentation'] ?? null);
    }
}
