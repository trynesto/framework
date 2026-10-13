<?php

declare(strict_types=1);

namespace Nesto\Orm\Schema;

/** A FOREIGN KEY constraint. `explicitName` false: the name was chosen by the database / is not compared. */
final class ForeignKey
{
    /**
     * @param list<string> $columns
     * @param list<string> $referencedColumns
     */
    public function __construct(
        public readonly array $columns,
        public readonly string $referencedTable,
        public readonly array $referencedColumns,
        public readonly ?string $onDelete = null,
        public readonly ?string $onUpdate = null,
        public readonly ?string $name = null,
        public readonly bool $explicitName = false,
    ) {}
}
