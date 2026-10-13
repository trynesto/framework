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

/** closure-table tree (see TreeScenarios). */
#[Entity('tree_closure_categories')]
#[Tree('closure-table')]
class ClosureCategory
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    #[TreeParent(onDelete: 'CASCADE')]
    public ?ClosureCategory $parent = null;

    /** @var list<ClosureCategory> */
    #[TreeChildren(cascade: true)]
    public array $children;

    #[TreeLevelColumn]
    public int $level;
}
