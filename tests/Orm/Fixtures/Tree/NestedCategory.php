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

/** nested-set tree (see TreeScenarios). */
#[Entity('tree_nested_categories')]
#[Tree('nested-set')]
class NestedCategory
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    #[TreeParent(onDelete: 'CASCADE')]
    public ?NestedCategory $parent = null;

    /** @var list<NestedCategory> */
    #[TreeChildren(cascade: true)]
    public array $children;

    #[TreeLevelColumn]
    public int $level;
}
