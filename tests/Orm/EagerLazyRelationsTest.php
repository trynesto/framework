<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\JoinColumn;
use Nesto\Orm\Attributes\OneToMany;
use Nesto\Orm\Attributes\OneToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;
use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\LazyCollection;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\OrmException;
use Nesto\Tests\Orm\Fixtures\Eager\CyclicEager;
use Nesto\Tests\Orm\Fixtures\Eager\EgCity;
use Nesto\Tests\Orm\Fixtures\Eager\EgCountry;
use Nesto\Tests\Orm\Fixtures\Eager\EgStreet;
use Nesto\Tests\Orm\Fixtures\Eager\SelfEager;
use Nesto\Tests\Orm\Fixtures\Lazy\LzAuthor;
use Nesto\Tests\Orm\Fixtures\Lazy\LzBook;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** `eager: true` (find*() only, recursive, cycles rejected) and `lazy: true` (PHP 8.4+ lazy ghosts / LazyCollection). */
final class EagerLazyRelationsTest extends TestCase
{
    private DataSource $ds;
    private EntityManager $em;

    protected function setUp(): void
    {
        MetadataRegistry::clear();
    }

    private function eagerData(): void
    {
        $this->ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [EgCountry::class, EgCity::class, EgStreet::class], 'synchronize' => true]);
        $this->em = $this->ds->getEntityManager();
        $iraq = $this->em->save($this->em->create(EgCountry::class, ['name' => 'Iraq']));
        $baghdad = $this->em->create(EgCity::class, ['name' => 'Baghdad']);
        $baghdad->country = $iraq;
        $baghdad->streets = [$this->em->create(EgStreet::class, ['name' => 'Rasheed']), $this->em->create(EgStreet::class, ['name' => 'Mutanabbi'])];
        $this->em->save($baghdad);
    }

    private function queries(callable $fn): int
    {
        $conn = $this->ds->getConnection()->enableQueryLog()->flushQueryLog();
        $fn();

        return $conn->queryCount();
    }

    public function testFindLoadsEagerRelationsRecursively(): void
    {
        $this->eagerData();
        $streets = [];
        $this->assertSame(3, $this->queries(function () use (&$streets) {
            $streets = $this->em->find(EgStreet::class);
        }), 'streets, their cities, the cities\' countries');
        $this->assertSame(['Baghdad', 'Baghdad'], [$streets[0]->city->name, $streets[1]->city->name]);
        $this->assertSame('Iraq', $streets[0]->city->country->name);

        $one = $this->em->findOneOrFail(EgStreet::class, ['where' => ['name' => 'Mutanabbi']]);
        $this->assertSame('Iraq', $one->city->country->name);

        $joined = [];
        $this->assertSame(1, $this->queries(function () use (&$joined) {
            $joined = $this->em->find(EgStreet::class, ['relationLoadStrategy' => 'join']);
        }));
        $this->assertSame('Iraq', $joined[1]->city->country->name);
    }

    public function testEagerRelationsOfExplicitRelationsAndOptOut(): void
    {
        $this->eagerData();
        $country = $this->em->findOneOrFail(EgCountry::class, ['relations' => ['cities.streets']]);
        $this->assertSame(['Rasheed', 'Mutanabbi'], array_map(fn (EgStreet $s) => $s->name, $country->cities[0]->streets));
        $this->assertSame('Iraq', $country->cities[0]->country->name, 'eager relation of an explicitly loaded relation');

        $plain = $this->em->findOneOrFail(EgStreet::class, ['loadEagerRelations' => false]);
        $this->assertFalse((new \ReflectionProperty($plain, 'city'))->isInitialized($plain), 'not loaded');
        $this->assertSame(2, $this->em->getRepository(EgStreet::class)->count(['where' => ['city' => ['country' => ['name' => 'Iraq']]]]));
    }

    public function testQueryBuilderIgnoresEagerRelations(): void
    {
        $this->eagerData();
        $street = $this->em->createQueryBuilder(EgStreet::class, 's')->getOneOrFail();
        $this->assertFalse((new \ReflectionProperty($street, 'city'))->isInitialized($street));
        $street = $this->em->createQueryBuilder(EgStreet::class, 's')->leftJoinAndSelect('s.city', 'c')->getOneOrFail();
        $this->assertSame('Baghdad', $street->city->name);
        $this->assertFalse((new \ReflectionProperty($street->city, 'country'))->isInitialized($street->city));
    }

    public function testCircularEagerRelationsAreRejected(): void
    {
        try {
            new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [CyclicEager::class]]);
            $this->fail('expected an exception');
        } catch (OrmException $e) {
            $this->assertStringContainsString('Circular eager relations are disallowed: ' . CyclicEager::class . '::$owner → ' . Fixtures\Eager\CyclicEagerOwner::class . '::$items', $e->getMessage());
            $this->assertStringContainsString('Remove eager: true from one of them', $e->getMessage());
        }
        $this->expectException(OrmException::class);
        $this->expectExceptionMessage('Circular eager relations are disallowed: ' . SelfEager::class . '::$parent (eager: true) lead back to ' . SelfEager::class);
        new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [SelfEager::class]]);
    }

    // ---- lazy -----------------------------------------------------------------------------------

    public function testLazyRelationsNeedPhp84(): void
    {
        if (PHP_VERSION_ID >= 80400) {
            $this->assertTrue(MetadataRegistry::get(LzBook::class)->relation('author')->lazy);

            return;
        }
        $this->expectException(OrmException::class);
        $this->expectExceptionMessage('Lazy relations need PHP 8.4+ (native lazy objects): ' . LzBook::class . '::$author uses lazy: true on PHP ' . PHP_VERSION);
        MetadataRegistry::get(LzBook::class);
    }

    public function testLazyToOneIsAGhostLoadedOnFirstAccess(): void
    {
        if (PHP_VERSION_ID < 80400) {
            $this->expectException(OrmException::class);
            MetadataRegistry::get(LzBook::class);

            return;
        }
        $this->lazyData();
        $book = $this->em->findOneOrFail(LzBook::class, ['where' => ['title' => 'B1']]);
        $author = $book->author;
        $this->assertInstanceOf(LzAuthor::class, $author);
        $this->assertTrue((new ReflectionClass($author))->isUninitializedLazyObject($author));
        $this->assertSame(0, $this->queries(fn () => $this->assertSame(1, $author->id)), 'the key is known without a query');
        $this->assertSame(1, $this->queries(fn () => $this->assertSame('Ada', $author->name)));
        $this->assertFalse((new ReflectionClass($author))->isUninitializedLazyObject($author));
        $this->assertSame(0, $this->queries(fn () => $author->name));

        // saving the book does not load (or write) the author; explicit relations replace the ghost
        $other = $this->em->findOneOrFail(LzBook::class, ['where' => ['title' => 'B2']]);
        $other->title = 'B2!';
        $this->assertSame(1, $this->queries(fn () => $this->em->save($other)));
        $this->assertTrue((new ReflectionClass($other->author))->isUninitializedLazyObject($other->author));
        $loaded = $this->em->findOneOrFail(LzBook::class, ['where' => ['title' => 'B1'], 'relations' => ['author']]);
        $this->assertFalse((new ReflectionClass($loaded->author))->isUninitializedLazyObject($loaded->author));
        $this->assertNull($this->em->findOneOrFail(LzBook::class, ['where' => ['title' => 'Orphan']])->author, 'NULL foreign key → null');
    }

    public function testLazyToManyIsALazyCollection(): void
    {
        if (PHP_VERSION_ID < 80400) {
            $this->expectException(OrmException::class);
            MetadataRegistry::get(LzAuthor::class);

            return;
        }
        $this->lazyData();
        $author = $this->em->findOneOrFail(LzAuthor::class);
        $this->assertInstanceOf(LazyCollection::class, $author->books);
        $this->assertFalse($author->books->isLoaded());
        $this->assertSame(0, $this->queries(fn () => $this->em->save($author)), 'not loaded: save() leaves it alone');
        $this->assertSame(1, $this->queries(fn () => $this->assertCount(2, $author->books)));
        $this->assertSame(0, $this->queries(fn () => $this->assertSame(['B1', 'B2'], array_map(fn (LzBook $b) => $b->title, $author->books->toArray()))));
        $this->assertSame('B2', $author->books[1]->title);

        // a loaded collection is saved like an array: removing a book nullifies it (orphanedRowAction default)
        unset($author->books[0]);
        $this->em->save($author);
        $this->assertSame([null, 1, null], array_map(fn (array $r) => $r['author_id'] === null ? null : (int) $r['author_id'], $this->ds->getConnection()->fetchAll('SELECT author_id FROM lz_books ORDER BY id')));

        $explicit = $this->em->findOneOrFail(LzAuthor::class, ['relations' => ['books']]);
        $this->assertIsArray($explicit->books);
        $this->assertSame(['B2'], array_map(fn (LzBook $b) => $b->title, $explicit->books));

        // JSON never triggers a collection load (TypeORM leaves unloaded lazy relations out); a ghost is loaded
        $fresh = $this->em->findOneOrFail(LzAuthor::class);
        $this->assertSame('{"id":1,"name":"Ada","books":null}', json_encode($fresh));
        $fresh->books->toArray();
        $this->assertSame('{"id":1,"name":"Ada","books":[{"id":2,"title":"B2","author":{"id":1,"name":"Ada","books":null}}]}', json_encode($fresh));
    }

    public function testInvalidLazyDeclarations(): void
    {
        if (PHP_VERSION_ID < 80400) {
            $this->expectException(OrmException::class);
            $this->expectExceptionMessage('Lazy relations need PHP 8.4+');
            MetadataRegistry::get(LazyInverseOneToOne::class);

            return;
        }
        try {
            MetadataRegistry::get(LazyInverseOneToOne::class);
            $this->fail('expected an exception');
        } catch (OrmException $e) {
            $this->assertStringContainsString('lazy: true is only supported on the owning side of a OneToOne', $e->getMessage());
        }
        $this->expectException(OrmException::class);
        $this->expectExceptionMessage('declare the property as `iterable`');
        MetadataRegistry::get(LazyArrayCollection::class);
    }

    private function lazyData(): void
    {
        $this->ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [LzAuthor::class, LzBook::class], 'synchronize' => true]);
        $this->em = $this->ds->getEntityManager();
        $ada = $this->em->create(LzAuthor::class, ['name' => 'Ada']);
        $ada->books = [$this->em->create(LzBook::class, ['title' => 'B1']), $this->em->create(LzBook::class, ['title' => 'B2'])];
        $this->em->save($ada);
        $this->em->save($this->em->create(LzBook::class, ['title' => 'Orphan']));
    }
}

#[Entity(name: 'lz_bad_inverse')]
final class LazyInverseOneToOne
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[OneToOne(LazyOwningSide::class, inverseSide: 'owner', lazy: true)]
    public ?LazyOwningSide $side = null;
}

#[Entity(name: 'lz_bad_owning')]
final class LazyOwningSide
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[OneToOne(LazyInverseOneToOne::class, inverseSide: 'side')]
    #[JoinColumn]
    public ?LazyInverseOneToOne $owner = null;
}

#[Entity(name: 'lz_bad_array')]
final class LazyArrayCollection
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[OneToMany(LzBook::class, inverseSide: 'author', lazy: true)]
    public array $books;
}
