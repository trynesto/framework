<?php

declare(strict_types=1);

namespace Nesto\Orm\Mapping;

/**
 * The #[Tree] of an entity: its type, the #[TreeParent] / #[TreeChildren] / #[TreeLevelColumn] properties and
 * the bookkeeping storage of the type (closure junction table, nested-set or materialized-path columns, which
 * are table columns without a property, like TypeORM's).
 */
final class TreeMetadata
{
    public const NESTED_SET_LEFT = 'nsleft';
    public const NESTED_SET_RIGHT = 'nsright';
    public const MATERIALIZED_PATH = 'mpath';

    public function __construct(
        public readonly string $type,
        public readonly string $parentProperty,
        public readonly ?string $childrenProperty = null,
        public readonly ?string $levelProperty = null,
        public readonly ?string $closureTable = null,
        public readonly ?string $ancestorColumn = null,
        public readonly ?string $descendantColumn = null,
    ) {}

    public function isClosureTable(): bool
    {
        return $this->type === 'closure-table';
    }

    public function isNestedSet(): bool
    {
        return $this->type === 'nested-set';
    }

    public function isMaterializedPath(): bool
    {
        return $this->type === 'materialized-path';
    }

    public function isAdjacencyList(): bool
    {
        return $this->type === 'adjacency-list';
    }
}
