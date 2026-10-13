<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\Attributes\{ChildEntity, Column, Entity, PrimaryGeneratedColumn, TableInheritance};
use Nesto\Orm\DataSource;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\OrmException;
use Nesto\Tests\Orm\Fixtures\Inheritance\CtiCat;
use Nesto\Tests\Orm\Fixtures\Inheritance\CtiDog;
use Nesto\Tests\Orm\Fixtures\Inheritance\StiAuthor;
use Nesto\Tests\Orm\Fixtures\Inheritance\StiContent;
use Nesto\Tests\Orm\Fixtures\Inheritance\StiHdVideo;
use Nesto\Tests\Orm\Fixtures\Inheritance\StiPhoto;
use Nesto\Tests\Orm\Fixtures\Inheritance\StiVideo;
use PHPUnit\Framework\TestCase;

#[Entity('inh_late')]
#[TableInheritance]
class InhLateRoot { #[PrimaryGeneratedColumn] public int $id; #[Column] public string $name; }

#[Entity('inh_plain')]
class InhPlain { #[PrimaryGeneratedColumn] public int $id; }

#[ChildEntity]
class InhOrphanChild extends InhPlain {}

#[Entity('inh_both')]
#[TableInheritance]
class InhBothRoot { #[PrimaryGeneratedColumn] public int $id; }

#[Entity]
#[ChildEntity]
class InhBoth extends InhBothRoot {}

#[Entity('inh_dupe')]
#[TableInheritance]
class InhDupeRoot { #[PrimaryGeneratedColumn] public int $id; }

#[ChildEntity('same')]
class InhDupeA extends InhDupeRoot {}

#[ChildEntity('same')]
class InhDupeB extends InhDupeRoot {}

#[Entity('inh_ctp')]
#[TableInheritance(pattern: 'CTI')]
class InhClassTablePattern { #[PrimaryGeneratedColumn] public int $id; }

/** Entity inheritance metadata: concrete table inheritance and single table inheritance (see InheritanceTreeScenarios for the 4-driver behaviour). */
final class InheritanceTest extends TestCase
{
    protected function setUp(): void
    {
        MetadataRegistry::clear();
    }

    public function testConcreteTableInheritanceTakesEverythingFromTheParents(): void
    {
        $cat = MetadataRegistry::get(CtiCat::class);
        $this->assertSame('cti_cats', $cat->table);
        $this->assertSame(['id', 'name', 'audit_by', 'audit_note', 'created_at', 'updated_at', 'deleted_at', 'version', 'lives'], array_keys($cat->columns), 'parent columns first, like TypeORM');
        $this->assertSame('audit.by', $cat->column('audit_by')->property);
        $this->assertSame(['createdAt', 'updatedAt', 'deletedAt', 'version'], [$cat->createdAt, $cat->updatedAt, $cat->deletedAt, $cat->version]);
        $this->assertSame([['columns' => ['name'], 'unique' => false, 'name' => null, 'where' => null]], $cat->indexes, 'class-level #[Index] of the parent');
        $this->assertNull($cat->inheritanceRoot);
        $this->assertSame([['columns' => ['name', 'breed'], 'name' => null]], MetadataRegistry::get(CtiDog::class)->uniques);
    }

    public function testSingleTableInheritanceMetadata(): void
    {
        // a DataSource declares its entity classes before building metadata: the root finds its children
        new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [StiContent::class, StiPhoto::class, StiVideo::class, StiHdVideo::class]]);
        $root = MetadataRegistry::get(StiContent::class);
        $photo = MetadataRegistry::get(StiPhoto::class);
        $video = MetadataRegistry::get(StiVideo::class);
        $hd = MetadataRegistry::get(StiHdVideo::class);
        $this->assertSame($root, MetadataRegistry::get(StiContent::class), 'built once');
        foreach ([$root, $photo, $video, $hd] as $meta) {
            $this->assertSame('sti_contents', $meta->table);
            $this->assertSame(StiContent::class, $meta->inheritanceRoot);
            $this->assertSame('kind', $meta->discriminatorColumn);
            $this->assertSame('discriminator', $meta->columns['kind']->special);
            $this->assertNull($meta->columns['kind']->property);
        }
        $this->assertSame(['StiContent', 'photo', 'StiVideo', 'hd'], [$root->discriminatorValue, $photo->discriminatorValue, $video->discriminatorValue, $hd->discriminatorValue]);
        $this->assertSame(['StiContent' => StiContent::class, 'photo' => StiPhoto::class, 'StiVideo' => StiVideo::class, 'hd' => StiHdVideo::class], $root->discriminatorMap);
        $this->assertSame(['StiVideo' => StiVideo::class, 'hd' => StiHdVideo::class], $video->discriminatorMap);
        $this->assertFalse($root->isChildEntity());
        $this->assertNull($root->discriminatorValues(), 'the root sees every row');
        $this->assertSame(['StiVideo', 'hd'], $video->discriminatorValues());
        $this->assertSame(['hd'], $hd->discriminatorValues());

        // the root selects (and can be queried by) its children's columns; a child maps its own + inherited ones
        $this->assertArrayHasKey('resolution', $root->selectColumns());
        $this->assertArrayNotHasKey('resolution', $root->columns);
        $this->assertSame('photographer_id', $root->columnName('photographer'));
        $this->assertSame(['id', 'title', 'created_at', 'version', 'size', 'lens', 'author_id', 'photographer_id', 'kind'], array_keys($photo->columns));
        $this->assertSame(['author', 'photographer'], array_keys($photo->relations));
        $this->assertSame([['columns' => ['title'], 'unique' => false, 'name' => null, 'where' => null]], $hd->indexes);
    }

    public function testInvalidHierarchiesAreRejected(): void
    {
        foreach ([
            [InhOrphanChild::class, 'must extend an #[Entity] marked #[TableInheritance]'],
            [InhBoth::class, 'keep only #[ChildEntity]'],
            [InhDupeRoot::class, 'share the discriminator value "same"'],
            [InhClassTablePattern::class, 'only supports the "STI" pattern'],
        ] as [$class, $message]) {
            try {
                MetadataRegistry::get($class);
                $this->fail("$class should be rejected");
            } catch (OrmException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $class);
            }
        }
    }

    public function testAChildDeclaredAfterItsRootWasBuiltRebuildsTheRoot(): void
    {
        $this->assertSame(['InhLateRoot'], array_keys(MetadataRegistry::get(InhLateRoot::class)->discriminatorMap));
        $late = new #[ChildEntity('late')] class extends InhLateRoot {};
        $child = MetadataRegistry::get($late::class);
        $this->assertSame(['late'], $child->discriminatorValues());
        $this->assertSame(['InhLateRoot', 'late'], array_keys(MetadataRegistry::get(InhLateRoot::class)->discriminatorMap));
    }

    public function testRowsAreHydratedAsTheirDiscriminatorsClass(): void
    {
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'synchronize' => true, 'entities' => [StiAuthor::class, StiContent::class, StiPhoto::class, StiVideo::class, StiHdVideo::class]]);
        $conn = $ds->getConnection();
        $conn->execute("INSERT INTO sti_contents (title, version, kind, size) VALUES ('p', 1, 'photo', 3)");
        $conn->execute("INSERT INTO sti_contents (title, version, kind, duration, resolution) VALUES ('h', 1, 'hd', 4, '8k')");
        $rows = $ds->getRepository(StiContent::class)->find();
        $this->assertSame([StiPhoto::class, StiHdVideo::class], array_map(fn (object $c) => $c::class, $rows));
        $this->assertSame([3, '8k'], [$rows[0]->size, $rows[1]->resolution]);
        $this->assertSame(['p'], array_map(fn (object $c) => $c->title, $ds->getRepository(StiContent::class)->find(['where' => ['size' => 3]])), 'parent queried by a child column');

        $conn->execute("INSERT INTO sti_contents (title, version, kind) VALUES ('?', 1, 'audio')");
        $this->expectException(OrmException::class);
        $this->expectExceptionMessage('holds "audio", which is not the discriminator value');
        $ds->getRepository(StiContent::class)->find();
    }
}
