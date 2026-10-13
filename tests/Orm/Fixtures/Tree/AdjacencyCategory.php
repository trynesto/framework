<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Tree;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;
use Nesto\Orm\Attributes\Tree;
use Nesto\Orm\Attributes\TreeChildren;
use Nesto\Orm\Attributes\TreeLevelColumn;
use Nesto\Orm\Attributes\TreeParent;

/** adjacency-list tree (see TreeScenarios). */
#[Entity('tree_adjacency_categories')]
#[Tree('adjacency-list')]
class AdjacencyCategory
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    #[TreeParent(onDelete: 'CASCADE')]
    public ?AdjacencyCategory $parent = null;

    /** @var list<AdjacencyCategory> */
    #[TreeChildren(cascade: true)]
    public array $children;

    #[TreeLevelColumn]
    public int $level;
}
