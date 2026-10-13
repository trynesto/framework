<?php

declare(strict_types=1);

namespace Nesto\Orm;

/**
 * Result of upsert() (TypeORM's InsertResult): the primary key of every row written, in input order
 * (`identifiers`: [['id' => 1], ['id' => 7]]), the generated values written back onto the entities
 * (`generatedMaps`) and the driver's affected-row count (`raw`; MySQL counts an updated row as 2).
 */
final class InsertResult
{
    /**
     * @param list<array<string, mixed>> $identifiers
     * @param list<array<string, mixed>> $generatedMaps
     */
    public function __construct(
        public readonly array $identifiers,
        public readonly array $generatedMaps,
        public readonly int $raw,
    ) {}
}
