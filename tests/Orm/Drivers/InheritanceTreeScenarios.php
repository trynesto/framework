<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers;

use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\Migration\MigrationGenerator;
use Nesto\Orm\Migration\Migrator;
use Nesto\Orm\OrmException;
use Nesto\Orm\TreeRepository;
use Nesto\Tests\Orm\Fixtures\Inheritance\CtiCat;
use Nesto\Tests\Orm\Fixtures\Inheritance\CtiDog;
use Nesto\Tests\Orm\Fixtures\Inheritance\StiAuthor;
use Nesto\Tests\Orm\Fixtures\Inheritance\StiContent;
use Nesto\Tests\Orm\Fixtures\Inheritance\StiHdVideo;
use Nesto\Tests\Orm\Fixtures\Inheritance\StiPhoto;
use Nesto\Tests\Orm\Fixtures\Inheritance\StiVideo;
use Nesto\Tests\Orm\Fixtures\Tree\AdjacencyCategory;
use Nesto\Tests\Orm\Fixtures\Tree\ClosureCategory;
use Nesto\Tests\Orm\Fixtures\Tree\NestedCategory;
use Nesto\Tests\Orm\Fixtures\Tree\PathCategory;

/**
 * Entity inheritance (concrete table, single table) and tree entities (closure table, nested set, materialized
 * path, adjacency list) on every driver, with the Inheritance / Tree fixtures (tables sti_*, cti_*, tree_*,
 * dropped before and after each scenario). Used by DriverTestCase.
 */
trait InheritanceTreeScenarios
{
    private ?DataSource $hierarchy = null;

    /** @return list<class-string> */
    private static function hierarchyEntities(): array
    {
        return [
            StiAuthor::class, StiContent::class, StiPhoto::class, StiVideo::class, StiHdVideo::class, CtiCat::class, CtiDog::class,
            ClosureCategory::class, NestedCategory::class, PathCategory::class, AdjacencyCategory::class,
        ];
    }

    private function hierarchyDataSource(bool $synchronize = true): DataSource
    {
        $ds = new DataSource(['entities' => self::hierarchyEntities()]);
        $ds->useConnection($this->ds->getConnection());
        $ds->initialize();
        $ds->synchronizer()->drop(self::hierarchyEntities());
        if ($synchronize) {
            $ds->synchronize();
        }

        return $this->hierarchy = $ds;
    }

    #[\PHPUnit\Framework\Attributes\After]
    protected function dropHierarchyTables(): void
    {
        if ($this->hierarchy !== null) {
            $conn = $this->hierarchy->getConnection();
            while ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $this->hierarchy->synchronizer()->drop(self::hierarchyEntities());
            $this->hierarchy = null;
        }
    }

    public function testInheritanceAndTreeSchemasSynchronizeAndGenerateMigrations(): void
    {
        $ds = $this->hierarchyDataSource(synchronize: false);
        $entities = self::hierarchyEntities();
        $sync = $ds->synchronizer();

        // migration:generate from nothing → run it → nothing left to generate
        $dir = sys_get_temp_dir() . '/nesto-hier-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $class = 'HierarchyMigration' . ucfirst(static::platform()) . bin2hex(random_bytes(3));
            $generator = new MigrationGenerator($ds->schema());
            $code = (string) $generator->generate($sync->tables($entities), $class);
            $this->assertStringContainsString("createTable('sti_contents'", $code);
            $this->assertStringContainsString("createTable('tree_closure_categories_closure'", $code);
            $this->assertSame(1, substr_count($code, "createTable('sti_contents'"), 'one table for the whole hierarchy');
            file_put_contents("$dir/2026_01_01_000000_hier.php", $code);
            $migrator = new Migrator($ds->getConnection(), [$dir], 'hier_migrations');
            $this->assertSame([$class], $migrator->run());
            $this->assertDiffEmpty($sync->diff($entities), "after the generated migration\n$code");
            $this->assertNull($generator->generate($sync->tables($entities), $class . 'Again'));
            $this->assertSame($class, $migrator->revert());
            $this->assertFalse($ds->schema()->hasTable('sti_contents'));
        } finally {
            array_map('unlink', glob("$dir/*.php") ?: []);
            rmdir($dir);
            $ds->schema()->dropTableIfExists('hier_migrations');
        }

        // synchronize → diff empty, a second synchronize runs no DDL
        $sync->synchronize($entities);
        $this->assertDiffEmpty($sync->diff($entities), 'inheritance / tree fixtures');
        $this->assertSame([], $this->ddl(fn () => $sync->synchronize($entities)), 'a second synchronize runs no DDL');
        // the child entities alone describe the same single table
        $this->assertDiffEmpty($sync->diff([StiPhoto::class, StiHdVideo::class]), 'child entities only');

        $details = array_column($ds->schema()->columnDetails('sti_contents'), null, 'name');
        foreach (['kind', 'title', 'size', 'lens', 'photographer_id', 'duration', 'resolution', 'author_id'] as $column) {
            $this->assertArrayHasKey($column, $details, $column);
        }
        $this->assertFalse($details['kind']['nullable']);
        $this->assertFalse($details['title']['nullable']);
        foreach (['size', 'duration', 'resolution', 'photographer_id'] as $childOnly) {
            $this->assertTrue($details[$childOnly]['nullable'], "$childOnly is nullable (child-only column)");
        }
        $this->assertSame(['id', 'name', 'audit_by', 'audit_note', 'created_at', 'updated_at', 'deleted_at', 'version', 'lives'], $ds->schema()->columns('cti_cats'));
        $this->assertTrue($ds->schema()->hasTable('tree_closure_categories_closure'));
        $this->assertSame(['id_ancestor', 'id_descendant'], $ds->schema()->columns('tree_closure_categories_closure'));
        $this->assertContains('nsleft', $ds->schema()->columns('tree_nested_categories'));
        $this->assertContains('mpath', $ds->schema()->columns('tree_path_categories'));
    }

    public function testSingleTableInheritance(): void
    {
        $ds = $this->hierarchyDataSource();
        $authors = $ds->getRepository(StiAuthor::class);
        $contents = $ds->getRepository(StiContent::class);
        $photos = $ds->getRepository(StiPhoto::class);
        $videos = $ds->getRepository(StiVideo::class);
        $hd = $ds->getRepository(StiHdVideo::class);

        $ada = $authors->save($authors->create(['name' => 'Ada']));
        $bob = $authors->save($authors->create(['name' => 'Bob']));
        $sunset = $photos->save($photos->create(['title' => ' Sunset ', 'size' => 10, 'author' => $ada, 'photographer' => $bob]));
        $clip = $videos->save($videos->create(['title' => 'Clip', 'duration' => 5, 'author' => $ada]));
        $movie = $hd->save($hd->create(['title' => 'Movie', 'duration' => 90, 'resolution' => '4k', 'author' => $bob]));
        $this->assertSame('Sunset', $sunset->title, 'parent listeners run for children');
        $this->assertSame(1, $sunset->version);
        $kinds = $ds->getConnection()->fetchAll('SELECT ' . $this->q('id') . ', ' . $this->q('kind') . ' FROM ' . $this->q('sti_contents') . ' ORDER BY ' . $this->q('id'));
        $this->assertSame(['photo', 'StiVideo', 'hd'], array_column($kinds, 'kind'));

        // the root's repository returns every row as its own class, with the child columns
        $all = $contents->find(['order' => ['id' => 'ASC']]);
        $this->assertSame([StiPhoto::class, StiVideo::class, StiHdVideo::class], array_map(fn (object $c) => $c::class, $all));
        $this->assertSame(10, $all[0]->size);
        $this->assertSame('4k', $all[2]->resolution);
        $this->assertSame(3, $contents->count());
        $this->assertInstanceOf(StiHdVideo::class, $contents->findOneBy(['id' => $movie->id]));
        $this->assertSame(['Clip', 'Movie'], array_map(fn (object $c) => $c->title, $contents->find(['where' => ['duration' => \Nesto\Orm\Operators\MoreThan(1)], 'order' => ['id' => 'ASC']])));

        // a child's repository only sees its type (and its children's): find, count, QueryBuilder
        $this->assertSame(['Clip', 'Movie'], array_map(fn (object $v) => $v->title, $videos->find(['order' => ['id' => 'ASC']])));
        $this->assertSame(2, $videos->count());
        $this->assertSame(1, $photos->count());
        $this->assertSame(1, $hd->count());
        $this->assertNull($videos->findOneBy(['id' => $sunset->id]));
        $this->assertNull($photos->findOneBy(['title' => 'Clip']));
        $this->assertSame(['Clip', 'Movie'], array_map(fn (object $v) => $v->title, $videos->createQueryBuilder('v')->where('v.duration > :d', ['d' => 0])->orderBy('v.id')->getMany()));
        $this->assertSame(2, $videos->createQueryBuilder('v')->getCount());
        $this->assertCount(1, $photos->createQueryBuilder('p')->select(['p.title'])->getMany(), 'partial selection keeps the type');
        $this->assertSame(2, (int) $videos->createQueryBuilder('v')->select('COUNT(*) AS n')->getRawOne()['n']);

        // update / delete / increment by criteria stay within the child's rows
        $this->assertSame(0, $photos->update(['title' => 'Clip'], ['title' => 'nope']));
        $this->assertSame(2, $videos->createQueryBuilder('v')->update()->set(['title' => 'Video'])->execute()); // empty criteria are refused by update()
        $this->assertSame('Sunset', $photos->findOneBy(['id' => $sunset->id])->title);
        $this->assertSame(0, $videos->delete(['id' => $sunset->id]));
        $this->assertSame(1, $photos->count());
        $videos->increment(['title' => 'Video'], 'duration', 1);
        $this->assertSame(6, $videos->findOneBy(['id' => $clip->id])->duration);
        $this->assertSame(10, $photos->findOneBy(['id' => $sunset->id])->size);

        // loaded through the parent, saved through its own repository: only changes are written
        $loaded = $contents->findOneBy(['id' => $sunset->id]);
        $this->assertInstanceOf(StiPhoto::class, $loaded);
        $loaded->lens = '50mm';
        $ds->getRepository(StiContent::class)->manager->save($loaded);
        $this->assertSame('50mm', $photos->findOneBy(['id' => $sunset->id])->lens);
        $this->assertSame(2, $photos->findOneBy(['id' => $sunset->id])->version);

        // relations pointing at the root, at a child, and a child-only relation
        $loadedAda = $authors->findOne(['where' => ['id' => $ada->id], 'relations' => ['contents', 'videos']]);
        $this->assertSame([StiPhoto::class, StiVideo::class], array_map(fn (object $c) => $c::class, $loadedAda->contents));
        $this->assertSame([StiVideo::class], array_map(fn (object $c) => $c::class, $loadedAda->videos), 'videos only, through the shared foreign key');
        $joined = $authors->find(['where' => ['id' => $ada->id], 'relations' => ['contents', 'videos'], 'relationLoadStrategy' => 'join']);
        $this->assertCount(2, $joined[0]->contents);
        $this->assertCount(1, $joined[0]->videos);
        $this->assertCount(1, $authors->createQueryBuilder('a')->innerJoinAndSelect('a.videos', 'v')->where('a.id = :id', ['id' => $ada->id])->getOne()->videos);
        $this->assertSame(['Ada'], array_map(fn (object $a) => $a->name, $authors->find(['where' => ['contents' => ['title' => 'Sunset']]])));
        $this->assertSame(['Ada', 'Bob'], array_map(fn (object $a) => $a->name, $authors->find(['where' => ['videos' => ['title' => 'Video']], 'order' => ['id' => 'ASC']])));
        $this->assertSame([], $authors->find(['where' => ['videos' => ['title' => 'Sunset']]]), 'a relation to a child entity never matches other types');

        $ada->cover = $sunset;
        $authors->save($ada);
        $withCover = $authors->findOne(['where' => ['id' => $ada->id], 'relations' => ['cover']]);
        $this->assertInstanceOf(StiPhoto::class, $withCover->cover);
        $photo = $photos->findOne(['where' => ['id' => $sunset->id], 'relations' => ['photographer', 'author']]);
        $this->assertSame('Bob', $photo->photographer->name);
        $this->assertSame('Ada', $photo->author->name);
        $this->assertNull($ds->getRepository(StiAuthor::class)->findOne(['where' => ['cover' => ['title' => 'Clip']]]), 'a photo relation never matches a video');

        // remove through the parent's manager, insert() / QueryBuilder insert write the discriminator
        $contents->manager->remove($all[1]);
        $this->assertSame(1, $videos->count());
        $videos->insert(['title' => 'Raw', 'duration' => 3]);
        $ds->createQueryBuilder()->insert()->into(StiPhoto::class)->values(['title' => 'QB', 'size' => 1])->execute();
        $this->assertSame(['Video', 'Raw'], array_map(fn (object $v) => $v->title, $videos->find(['order' => ['id' => 'ASC']])));
        $this->assertSame(['Sunset', 'QB'], array_map(fn (object $p) => $p->title, $photos->find(['order' => ['id' => 'ASC']])));
        $this->assertSame(4, $contents->count());

        // transactions: the transactional manager's child repository filters too
        $ds->transaction(function (EntityManager $tx): void {
            $this->assertSame(2, $tx->getRepository(StiVideo::class)->count());
            $tx->save($tx->create(StiHdVideo::class, ['title' => 'Tx', 'duration' => 1, 'resolution' => '8k']));
        });
        $this->assertSame(2, $hd->count());
    }

    public function testConcreteTableInheritance(): void
    {
        $ds = $this->hierarchyDataSource();
        $cats = $ds->getRepository(CtiCat::class);
        $dogs = $ds->getRepository(CtiDog::class);
        $tom = $cats->create(['name' => 'Tom', 'audit' => ['by' => 'ada']]);
        $cats->save($tom);
        $this->assertSame(['base insert', 'cat insert'], $tom->calls, 'listeners of the parent run first');
        $this->assertSame(1, $tom->version);
        $rex = $dogs->save($dogs->create(['name' => 'Rex', 'breed' => 'lab']));
        $this->assertSame(['base insert'], $rex->calls);

        $found = $cats->findOneBy(['audit' => ['by' => 'ada']]);
        $this->assertSame('Tom', $found->name);
        $this->assertSame(9, $found->lives);
        $found->name = 'Thomas';
        $cats->save($found);
        $this->assertSame(2, $found->version);
        $this->assertSame(['base update'], $found->calls);
        $cats->softRemove($found);
        $this->assertSame(0, $cats->count());
        $this->assertSame(1, $cats->count(['withDeleted' => true]));
        $this->assertSame(1, $dogs->count());
        $this->expectException(\Nesto\Orm\QueryException::class); // #[Unique(['name', 'breed'])] of the child
        $dogs->save($dogs->create(['name' => 'Rex', 'breed' => 'lab']));
    }

    /** @return list<array{0: class-string}> */
    private static function treeClasses(): array
    {
        return [[ClosureCategory::class], [NestedCategory::class], [PathCategory::class], [AdjacencyCategory::class]];
    }

    public function testTreesOfEveryType(): void
    {
        $ds = $this->hierarchyDataSource();
        foreach (self::treeClasses() as [$class]) {
            $this->runTreeScenario($ds, $class);
        }
    }

    /** @param class-string $class */
    private function runTreeScenario(DataSource $ds, string $class): void
    {
        $type = (string) \Nesto\Orm\MetadataRegistry::get($class)->tree?->type;
        $tree = $ds->getTreeRepository($class);
        $this->assertInstanceOf(TreeRepository::class, $ds->getRepository($class), "$type: getRepository() of a tree");

        // electronics ─┬─ phones ─┬─ android
        //              │          └─ ios
        //              └─ laptops
        $root = $tree->create(['name' => 'electronics']);
        $phones = $tree->create(['name' => 'phones']);
        $laptops = $tree->create(['name' => 'laptops']);
        $android = $tree->create(['name' => 'android']);
        $ios = $tree->create(['name' => 'ios']);
        $phones->children = [$android, $ios];
        $root->children = [$phones, $laptops];
        $tree->save($root); // TreeChildren cascade
        $this->assertSame([0, 1, 2, 1], [$root->level, $phones->level, $ios->level, $laptops->level], "$type levels");

        $names = fn (array $nodes): array => array_map(fn (object $n) => $n->name, $nodes);
        $shape = function (object $node) use (&$shape): array {
            return [$node->name => array_merge(...array_map($shape, $node->children ?? []) ?: [[]])];
        };
        $trees = $tree->findTrees();
        $this->assertCount(1, $trees, $type);
        $this->assertSame(['electronics' => ['phones' => ['android' => [], 'ios' => []], 'laptops' => []]], $shape($trees[0]), "$type findTrees");
        $this->assertSame(['electronics' => ['phones' => [], 'laptops' => []]], $shape($tree->findTrees(['depth' => 1])[0]), "$type findTrees depth 1");
        $this->assertSame(['electronics'], $names($tree->findRoots()), "$type findRoots");

        $this->assertSame(['phones', 'android', 'ios'], $names($tree->findDescendants($phones)), "$type findDescendants");
        $this->assertSame(3, $tree->countDescendants($phones));
        $this->assertSame(5, $tree->countDescendants($root));
        $this->assertSame(1, $tree->countDescendants($laptops));
        $descTree = $tree->findDescendantsTree($root, ['depth' => 1]);
        $this->assertSame($root, $descTree);
        $this->assertSame(['phones', 'laptops'], $names($descTree->children));
        $this->assertSame([], $descTree->children[0]->children, 'depth limit');
        $this->assertSame(['phones' => ['android' => [], 'ios' => []]], $shape($tree->findDescendantsTree($tree->findOneBy(['name' => 'phones']))));

        $this->assertSame(['electronics', 'phones', 'ios'], $names($tree->findAncestors($ios)), "$type findAncestors");
        $this->assertSame(3, $tree->countAncestors($ios));
        $this->assertSame(1, $tree->countAncestors($root));
        $iosTree = $tree->findAncestorsTree($tree->findOneBy(['name' => 'ios']));
        $this->assertSame('phones', $iosTree->parent->name);
        $this->assertSame('electronics', $iosTree->parent->parent->name);
        $this->assertNull($iosTree->parent->parent->parent);

        $qb = $tree->createDescendantsQueryBuilder('c', 'closure', $root)->andWhere('c.name LIKE :n', ['n' => '%on%'])->orderBy('c.id');
        $this->assertSame(['electronics', 'phones', 'android', 'ios', 'laptops'], $names($tree->findDescendants($root)));
        $this->assertSame(['electronics', 'phones', 'android', 'ios', 'laptops'], $names($tree->createDescendantsQueryBuilder('c', 'closure', $root)->orderBy('c.id')->getMany()));
        $this->assertSame(['electronics', 'phones'], $names($qb->getMany()));
        $this->assertSame(['phones', 'ios'], $names($tree->createAncestorsQueryBuilder('a', 'closure', $ios)->andWhere('a.level > :l', ['l' => 0])->orderBy('a.id')->getMany()));

        // a new leaf below an existing node
        $tablet = $tree->save($tree->create(['name' => 'tablets', 'parent' => $root]));
        $this->assertSame(1, $tablet->level);
        $this->assertSame(6, $tree->countDescendants($root));
        $this->assertSame(['electronics', 'tablets'], $names($tree->findAncestors($tablet)));

        if ($type === 'nested-set') {
            $this->assertNestedSet($ds, ['electronics' => [1, 12], 'phones' => [2, 7], 'android' => [3, 4], 'ios' => [5, 6], 'laptops' => [8, 9], 'tablets' => [10, 11]]);
            try {
                $tree->save($tree->create(['name' => 'second root']));
                $this->fail('a nested set has a single root');
            } catch (OrmException $e) {
                $this->assertStringContainsString('single root', $e->getMessage());
            }
            $phones->parent = $laptops;
            try {
                $tree->save($phones);
                $this->fail('nested-set nodes cannot move');
            } catch (OrmException $e) {
                $this->assertStringContainsString('cannot be moved', $e->getMessage());
            }
            $phones->parent = $root;
        } else {
            // move phones (with its subtree) below laptops
            $phones->parent = $laptops;
            $tree->save($phones);
            $this->assertSame(['electronics', 'phones', 'ios', 'laptops'], $names($tree->findAncestors($ios)), "$type after move (key order)");
            $this->assertSame(['phones', 'android', 'ios', 'laptops'], $names($tree->findDescendants($laptops)));
            $this->assertSame(['electronics' => ['laptops' => ['phones' => ['android' => [], 'ios' => []]], 'tablets' => []]], $shape($tree->findTrees()[0]), "$type tree after move");
            $this->assertSame(2, $phones->level);
            $this->assertSame([2, 3], [$tree->findOneBy(['name' => 'phones'])->level, $tree->findOneBy(['name' => 'ios'])->level]);
            try {
                $laptops->parent = $tree->findOneBy(['name' => 'android']);
                $tree->save($laptops);
                $this->fail('moving a node below its own descendant');
            } catch (OrmException $e) {
                $this->assertStringContainsString('descendant', $e->getMessage());
            }
            $laptops->parent = $root;
            // a moved node becomes a root
            $phones->parent = null;
            $tree->save($phones);
            $this->assertSame(['electronics', 'phones'], $names($tree->findRoots()));
            $this->assertSame(['phones', 'ios'], $names($tree->findAncestors($ios)));
            $this->assertSame([0, 1], [$tree->findOneBy(['name' => 'phones'])->level, $tree->findOneBy(['name' => 'ios'])->level]);
            $this->assertSame(1, $tree->countDescendants($laptops));
            $phones->parent = $root;
            $tree->save($phones);
            $this->assertSame(['electronics', 'phones', 'ios'], $names($tree->findAncestors($ios)));
        }

        // remove a subtree root: the foreign key cascades to its children, the bookkeeping follows
        $tree->remove($tree->findOneBy(['name' => 'phones']));
        $this->assertSame(['electronics', 'laptops', 'tablets'], $names($tree->find(['order' => ['id' => 'ASC']])), "$type after remove");
        $this->assertSame(['electronics', 'laptops', 'tablets'], $names($tree->findDescendants($root)));
        $this->assertSame(3, $tree->countDescendants($root));
        if ($type === 'nested-set') {
            $this->assertNestedSet($ds, ['electronics' => [1, 6], 'laptops' => [2, 3], 'tablets' => [4, 5]]);
        }
        if ($type === 'closure-table') {
            $this->assertSame(5, (int) $ds->getConnection()->fetchColumn('SELECT COUNT(*) FROM ' . $this->q('tree_closure_categories_closure')));
        }

        // the relations option and transactional tree repositories
        $ds->transaction(function (EntityManager $tx) use ($class, $root): void {
            $txTree = $tx->getTreeRepository($class);
            $this->assertSame($tx, $txTree->manager);
            $txTree->save($txTree->create(['name' => 'tv', 'parent' => $root]));
        });
        $withParents = $tree->findDescendants($root, ['relations' => ['parent']]);
        $this->assertSame([null, 'electronics', 'electronics', 'electronics'], array_map(fn (object $n) => $n->parent?->name, $withParents));
    }

    /** @param array<string, array{0: int, 1: int}> $expected name => [nsleft, nsright] */
    private function assertNestedSet(DataSource $ds, array $expected): void
    {
        $rows = $ds->getConnection()->fetchAll('SELECT ' . $this->q('name') . ', ' . $this->q('nsleft') . ', ' . $this->q('nsright') . ' FROM ' . $this->q('tree_nested_categories'));
        $actual = [];
        foreach ($rows as $row) {
            $actual[$row['name']] = [(int) $row['nsleft'], (int) $row['nsright']];
        }
        ksort($actual);
        ksort($expected);
        $this->assertSame($expected, $actual);
    }
}
