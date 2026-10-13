<?php

declare(strict_types=1);

namespace Nesto\Prisma\Dmmf;

/** A field of an index / unique / id definition with its options (`title(sort: Desc, length: 10)`). */
final class IndexField
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $sortOrder = null,
        public readonly ?int $length = null,
        public readonly ?string $operatorClass = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter(['name' => $this->name, 'sortOrder' => $this->sortOrder, 'length' => $this->length, 'operatorClass' => $this->operatorClass], fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['sortOrder'] ?? null, $data['length'] ?? null, $data['operatorClass'] ?? null);
    }
}
