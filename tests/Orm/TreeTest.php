<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\Attributes\{Column, CustomRepository, Entity, PrimaryGeneratedColumn, Tree, TreeChildren, TreeParent};
use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\OrmException;
use Nesto\Orm\TreeRepository;
use Nesto\Tests\Orm\Fixtures\Tag;
use Nesto\Tests\Orm\Fixtures\Tree\ClosureCategory;
use Nesto\Tests\Orm\Fixtures\Tree\NestedCategory;
use Nesto\Tests\Orm\Fixtures\Tree\PathCategory;
use PHPUnit\Framework\TestCase;

#[Entity('tree_custom')]
#[Tree('closure-table', ['closureTableName' => 'tree_custom_paths', 'ancestorColumnName' => 'up_id', 'descendantColumnName' => 'down_id'])]
class TreeCustomNode
{
    #[PrimaryGeneratedColumn] public int $id;
    #[Column] public string $name;
    #[TreeParent] public ?TreeCustomNode $parent = null;
}

#[Entity('tree_no_parent')]
#[Tree('materialized-path')]
class TreeWithoutParent { #[PrimaryGeneratedColumn] public int $id; }

#[Entity('tree_children_only')]
class TreeChildrenOnly { #[PrimaryGeneratedColumn] public int $id; #[TreeChildren] public array $children; }

#[CustomRepository(ClosureCategory::class)]
class ClosureCategoryRepository extends TreeRepository
{
    /** @return list<string> */
    public function rootNames(): array
    {
        return array_map(fn (object $c) => $c->name, $this->findRoots());
    }
}

/** Tree metadata, schema and repository wiring (see InheritanceTreeScenarios for the 4-driver behaviour). */
final class TreeTest extends TestCase
{
    protected function setUp(): void
    {
        MetadataRegistry::clear();
    }

    public function testTreeMetadataAndBookkeepingColumns(): void
    {
        $closure = MetadataRegistry::get(ClosureCategory::class)->tree;
        $this->assertSame(['closure-table', 'parent', 'children', 'level'], [$closure->type, $closure->parentProperty, $closure->childrenProperty, $closure->levelProperty]);
        $this->assertSame(['tree_closure_categories_closure', 'id_ancestor', 'id_descendant'], [$closure->closureTable, $closure->ancestorColumn, $closure->descendantColumn]);
        $parent = MetadataRegistry::get(ClosureCategory::class)->relation('parent');
        $this->assertSame([ClosureCategory::class, 'parent_id', 'children', 'CASCADE'], [$parent->target, $parent->joinColumn, $parent->inverseSide, $parent->onDelete]);
        $this->assertSame(['insert', 'update', 'remove', 'soft-remove', 'recover'], MetadataRegistry::get(ClosureCategory::class)->relation('children')->cascade);

        $nested = MetadataRegistry::get(NestedCategory::class);
        $this->assertNull($nested->columns['nsleft']->property);
        $this->assertSame([1, 2], [$nested->columns['nsleft']->default, $nested->columns['nsright']->default]);
        $path = MetadataRegistry::get(PathCategory::class);
        $this->assertTrue($path->columns['mpath']->nullable);

        $custom = MetadataRegistry::get(TreeCustomNode::class)->tree;
        $this->assertSame(['tree_custom_paths', 'up_id', 'down_id', null], [$custom->closureTable, $custom->ancestorColumn, $custom->descendantColumn, $custom->childrenProperty]);
    }

    public function testInvalidTreesAreRejected(): void
    {
        try {
            MetadataRegistry::get(TreeWithoutParent::class);
            $this->fail('a tree needs a parent');
        } catch (OrmException $e) {
            $this->assertStringContainsString('needs a #[TreeParent] property', $e->getMessage());
        }
        try {
            MetadataRegistry::get(TreeChildrenOnly::class);
            $this->fail('children need a parent');
        } catch (OrmException $e) {
            $this->assertStringContainsString('#[TreeChildren]', $e->getMessage());
        }
        $this->expectException(OrmException::class);
        $this->expectExceptionMessage('Unknown tree type "b-tree"');
        new Tree('b-tree');
    }

    public function testClosureTableGoesThroughTheSchemaBuilder(): void
    {
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [TreeCustomNode::class], 'synchronize' => true]);
        $this->assertSame(['up_id', 'down_id'], $ds->schema()->columns('tree_custom_paths'));
        $this->assertTrue($ds->synchronizer()->diff([TreeCustomNode::class])->isEmpty());
        $tree = $ds->getTreeRepository(TreeCustomNode::class);
        $a = $tree->save($tree->create(['name' => 'a']));
        $b = $tree->save($tree->create(['name' => 'b', 'parent' => $a]));
        $this->assertSame([['up_id' => 1, 'down_id' => 1], ['up_id' => 1, 'down_id' => 2], ['up_id' => 2, 'down_id' => 2]], array_map(
            fn (array $r) => array_map('intval', $r),
            $ds->getConnection()->fetchAll('SELECT up_id, down_id FROM tree_custom_paths ORDER BY up_id, down_id'),
        ));
        $this->assertSame(['a', 'b'], array_map(fn (object $n) => $n->name, $tree->findAncestors($b)));
        $this->expectException(OrmException::class);
        $this->expectExceptionMessage('needs a #[TreeChildren] property');
        $tree->findTrees();
    }

    public function testTreeRepositoriesEverywhere(): void
    {
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [ClosureCategory::class, Tag::class], 'synchronize' => true]);
        $this->assertInstanceOf(TreeRepository::class, $ds->getRepository(ClosureCategory::class));
        $this->assertSame($ds->getRepository(ClosureCategory::class), $ds->getTreeRepository(ClosureCategory::class));
        $this->assertSame($ds->getTreeRepository(ClosureCategory::class), $ds->manager->getTreeRepository(ClosureCategory::class));
        $this->assertNotInstanceOf(TreeRepository::class, $ds->getRepository(Tag::class));
        $ds->transaction(function (EntityManager $tx): void {
            $repo = $tx->getTreeRepository(ClosureCategory::class);
            $this->assertInstanceOf(TreeRepository::class, $repo);
            $this->assertSame($tx, $repo->manager);
        });

        // #[InjectRepository] goes through the DataSource's getRepository(); custom tree repositories extend TreeRepository
        $custom = new ClosureCategoryRepository($ds);
        $custom->save($custom->create(['name' => 'root']));
        $this->assertSame(['root'], $custom->rootNames());

        try {
            $ds->getTreeRepository(Tag::class);
            $this->fail('Tag is not a tree');
        } catch (OrmException $e) {
            $this->assertStringContainsString('is not a tree entity', $e->getMessage());
        }
        $this->expectException(OrmException::class);
        $this->expectExceptionMessage('save the node before querying');
        $ds->getTreeRepository(ClosureCategory::class)->findDescendants(new ClosureCategory);
    }
}
