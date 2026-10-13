<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Depth of a #[Tree] node (TypeORM's @TreeLevelColumn): 0 for roots, the parent's level + 1 otherwise. Written by
 * save() on insert and kept up to date for the whole subtree when a node moves.
 *
 *   #[TreeLevelColumn] public int $level;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class TreeLevelColumn
{
    public function __construct(public readonly ?string $name = null) {}
}
