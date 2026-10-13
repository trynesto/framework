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

/** materialized-path tree (see TreeScenarios). */
#[Entity('tree_path_categories')]
#[Tree('materialized-path')]
class PathCategory
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    #[TreeParent(onDelete: 'CASCADE')]
    public ?PathCategory $parent = null;

    /** @var list<PathCategory> */
    #[TreeChildren(cascade: true)]
    public array $children;

    #[TreeLevelColumn]
    public int $level;
}
