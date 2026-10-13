<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\MorphMany;
use Nesto\Orm\Attributes\MorphTo;
use Nesto\Orm\Attributes\OneToMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;
use Nesto\Orm\Attributes\RelationId;
use Nesto\Orm\DataSource;
use Nesto\Orm\Mapping\RelationMetadata;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\OrmException;
use Nesto\Tests\Orm\Fixtures\Library\LibAuthor;
use Nesto\Tests\Orm\Fixtures\Library\LibBook;
use Nesto\Tests\Orm\Fixtures\Library\LibChapter;
use PHPUnit\Framework\TestCase;

/** Relation options in the metadata (cascade / eager / orphanedRowAction / #[RelationId]) and their validation. */
final class RelationOptionsMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        MetadataRegistry::clear();
    }

    public function testOptionsAreNormalized(): void
    {
        $this->assertSame(RelationMetadata::CASCADE_OPERATIONS, MetadataRegistry::get(LibAuthor::class)->relation('books')->cascade, 'cascade: true');
        $author = MetadataRegistry::get(LibBook::class)->relation('author');
        $this->assertSame(['insert', 'update'], $author->cascade);
        $this->assertTrue($author->cascades('update'));
        $this->assertFalse($author->cascades('remove'));
        $this->assertNull($author->orphanedRowAction, 'nullify by default');
        $this->assertSame('delete', MetadataRegistry::get(LibChapter::class)->relation('book')->orphanedRowAction);
        $this->assertSame(['authorId' => 'author', 'genreIds' => 'genres'], MetadataRegistry::get(LibBook::class)->relationIds);
        $this->assertFalse(MetadataRegistry::get(LibBook::class)->relation('genres')->eager);
    }

    public function testInvalidOptionsAreRejected(): void
    {
        foreach ([
            BadCascade::class => 'Unknown cascade "delete" on ' . BadCascade::class . '::$parent',
            BadOrphanAction::class => 'Unknown orphanedRowAction "remove" on ' . BadOrphanAction::class . '::$parent',
            BadRelationId::class => "#[RelationId('nope')] on " . BadRelationId::class . '::$nopeId',
        ] as $class => $message) {
            try {
                MetadataRegistry::get($class);
                $this->fail("$class: expected an exception");
            } catch (OrmException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testEagerPolymorphicRelations(): void
    {
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [EgPost::class, EgPostNote::class], 'synchronize' => true]);
        $em = $ds->getEntityManager();
        $post = $em->create(EgPost::class, ['title' => 'Hello']);
        $post->notes = [$em->create(EgPostNote::class, ['text' => 'n1']), $em->create(EgPostNote::class, ['text' => 'n2'])];
        $em->save($post);

        $loaded = $em->findOneOrFail(EgPost::class);
        $this->assertSame(['n1', 'n2'], array_map(fn (EgPostNote $n) => $n->text, $loaded->notes), 'MorphMany eager');
        $this->assertSame('Hello', $loaded->notes[0]->notable->title, 'MorphTo eager of the eager notes');
        $note = $em->findOneOrFail(EgPostNote::class, ['where' => ['text' => 'n2']]);
        $this->assertSame('Hello', $note->notable->title);
        $this->assertFalse((new \ReflectionProperty($note->notable, 'notes'))->isInitialized($note->notable), 'eager relations of a MorphTo target (class known per row) are not followed');
    }
}

#[Entity(name: 'bad_cascade')]
final class BadCascade
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[ManyToOne(BadCascade::class, cascade: ['delete'])]
    public ?BadCascade $parent = null;
}

#[Entity(name: 'bad_orphan')]
final class BadOrphanAction
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[ManyToOne(BadOrphanAction::class, orphanedRowAction: 'remove')]
    public ?BadOrphanAction $parent = null;
}

#[Entity(name: 'bad_relation_id')]
final class BadRelationId
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[RelationId('nope')]
    public ?int $nopeId = null;

    /** @var list<BadRelationId> */
    #[OneToMany(BadRelationId::class, inverseSide: 'id')]
    public array $children;
}

#[Entity(name: 'eg_posts')]
final class EgPost
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $title;

    /** @var list<EgPostNote> */
    #[MorphMany(EgPostNote::class, 'notable', eager: true)]
    public array $notes;
}

#[Entity(name: 'eg_post_notes')]
final class EgPostNote
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $text;

    #[MorphTo(types: [EgPost::class], eager: true)]
    public ?object $notable = null;
}
