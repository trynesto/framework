<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;
use Nesto\Orm\OrmException;

/**
 * Tree entity (TypeORM's @Tree): a self-referencing hierarchy with #[TreeParent] / #[TreeChildren], queried
 * through a TreeRepository (getTreeRepository(), or #[InjectRepository] / getRepository() of a tree entity).
 *
 *   #[Entity] #[Tree('closure-table')]
 *   class Category {
 *       #[PrimaryGeneratedColumn] public int $id;
 *       #[Column] public string $name;
 *       #[TreeParent(onDelete: 'CASCADE')] public ?Category $parent = null;
 *       #[TreeChildren(cascade: true)] public array $children;
 *   }
 *
 * Types: 'closure-table' (junction table "{table}_closure": id_ancestor, id_descendant), 'nested-set' (nsleft /
 * nsright columns; a single root, nodes cannot be moved — like TypeORM), 'materialized-path' (mpath column
 * "1.4.9."), 'adjacency-list' (only the parent column; tree queries walk it level by level).
 * Closure-table options: closureTableName, ancestorColumnName, descendantColumnName.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Tree
{
    public const TYPES = ['closure-table', 'nested-set', 'materialized-path', 'adjacency-list'];

    /** @param array{closureTableName?: string, ancestorColumnName?: string, descendantColumnName?: string} $options */
    public function __construct(
        public readonly string $type,
        public readonly array $options = [],
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new OrmException("Unknown tree type \"$type\" (expected " . implode(', ', self::TYPES) . ').');
        }
    }
}
