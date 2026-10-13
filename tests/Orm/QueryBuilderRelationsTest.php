<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\OrmException;
use Nesto\Orm\QueryBuilder;
use Nesto\Tests\Orm\Fixtures\Library\LibAuthor;
use Nesto\Tests\Orm\Fixtures\Library\LibBio;
use Nesto\Tests\Orm\Fixtures\Library\LibBook;
use Nesto\Tests\Orm\Fixtures\Library\LibGenre;

/** QueryBuilder relation helpers: *JoinAndMap*, loadRelation*AndMap, partial selects, nested where, relation(). */
final class QueryBuilderRelationsTest extends LibraryTestCase
{
    public function testJoinAndMapOneAndMany(): void
    {
        $this->seed();
        $authors = $this->em->createQueryBuilder(LibAuthor::class, 'a')
            ->leftJoinAndMapOne('a.longestBook', 'a.books', 'lb', 'lb.pages > :min', ['min' => 250])
            ->leftJoinAndMapMany('a.longBooks', LibBook::class, 'b2', 'b2.author = a.id AND b2.pages >= :p', ['p' => 200])
            ->orderBy('a.id')
            ->getMany();
        $this->assertSame('Engines', $authors[0]->longestBook->title);
        $this->assertNull($authors[1]->longestBook);
        $this->assertSame(['Engines'], self::pluck($authors[0]->longBooks, 'title'));
        $this->assertSame(['Sailing'], self::pluck($authors[1]->longBooks, 'title'));
        $this->assertSame([], $authors[2]->longBooks);
        $this->assertFalse((new \ReflectionProperty($authors[0], 'books'))->isInitialized($authors[0]), 'the relation itself is untouched');

        $inner = $this->em->createQueryBuilder(LibAuthor::class, 'a')->innerJoinAndMapMany('a.longBooks', 'a.books', 'b', 'b.pages < :p', ['p' => 150])->getMany();
        $this->assertSame(['Ada'], self::pluck($inner, 'name'));
        $this->assertSame(['Notes'], self::pluck($inner[0]->longBooks, 'title'));

        $this->expectException(OrmException::class);
        $this->expectExceptionMessage(LibAuthor::class . ' has no property $nope');
        $this->em->createQueryBuilder(LibAuthor::class, 'a')->leftJoinAndMapOne('a.nope', 'a.books', 'b');
    }

    public function testLoadRelationCountAndIdAndMap(): void
    {
        $this->seed();
        $this->startCounting();
        $authors = $this->em->createQueryBuilder(LibAuthor::class, 'a')
            ->loadRelationCountAndMap('a.bookCount', 'a.books')
            ->loadRelationIdAndMap('a.bookIdList', 'a.books', 'book', fn (QueryBuilder $qb) => $qb->andWhere('book.pages > :p', ['p' => 150]))
            ->orderBy('a.id')
            ->getMany();
        $this->assertCount(4, $this->queries(), 'authors, #[RelationId] bookIds, the count, the ids');
        $this->assertSame([2, 1, 0], self::pluck($authors, 'bookCount'));
        $this->assertSame([[1], [3], []], self::pluck($authors, 'bookIdList'));

        $genres = $this->em->createQueryBuilder(LibGenre::class, 'g')->loadRelationCountAndMap('g.bookCount', 'g.books')->orderBy('g.id')->getMany();
        $this->assertSame([2, 1, 1, 0], self::pluck($genres, 'bookCount'));
    }

    public function testPartialSelectionHydratesPartialEntities(): void
    {
        $this->seed();
        $books = $this->em->createQueryBuilder(LibBook::class, 'b')->select(['b.title'])->leftJoinAndSelect('b.author', 'a')->addSelect('a.name')->orderBy('b.id')->getMany();
        $this->assertSame(['Engines', 'Notes', 'Sailing'], self::pluck($books, 'title'));
        $this->assertSame(1, $books[0]->id, 'primary key always selected');
        $this->assertFalse((new \ReflectionProperty($books[0], 'pages'))->isInitialized($books[0]));
        $this->assertSame('Ada', $books[0]->author->name);
        $this->assertFalse((new \ReflectionProperty($books[0]->author, 'rank'))->isInitialized($books[0]->author));
        // raw results keep alias.property as written
        $this->assertSame([['title' => 'Engines']], $this->em->createQueryBuilder(LibBook::class, 'b')->select('b.title')->where(['id' => 1])->getRawMany());
    }

    public function testNestedRelationConditionsJoinOnce(): void
    {
        $this->seed();
        $qb = $this->em->createQueryBuilder(LibBook::class, 'b')
            ->leftJoinAndSelect('b.author', 'a')
            ->where(['author' => ['name' => 'Ada']])
            ->andWhere(['genres' => ['name' => 'history']]);
        $this->assertSame(1, substr_count($qb->getSql(), 'JOIN "lib_authors"'), 'reuses the a join');
        $this->assertSame(['Engines'], self::pluck($qb->getMany(), 'title'));
        $this->assertSame('Ada', $qb->getOneOrFail()->author->name);
        $this->assertSame(1, $qb->getCount());
    }

    public function testRelationQueryBuilderManyToMany(): void
    {
        $e = $this->seed();
        $rqb = fn () => $this->em->createQueryBuilder()->relation(LibBook::class, 'genres');
        $rqb()->of($e['sailing'])->add([$e['poetry'], 2]);
        $this->assertSame(['history', 'travel', 'poetry'], self::pluck($rqb()->of($e['sailing'])->loadMany(), 'name'));
        $rqb()->of(3)->remove($e['travel']);
        $rqb()->of(['id' => 3])->addAndRemove([$e['science']], [2]);
        $this->assertSame(['science', 'poetry'], self::pluck($rqb()->of(3)->loadMany(), 'name'));

        // inverse side writes the same join table
        $this->em->createQueryBuilder()->relation(LibGenre::class, 'books')->of($e['poetry'])->add($e['engines']);
        $this->assertSame(['Engines', 'Sailing'], self::pluck($this->em->createQueryBuilder()->relation(LibGenre::class, 'books')->of($e['poetry'])->loadMany(), 'title'));
    }

    public function testRelationQueryBuilderOneToManyAndToOne(): void
    {
        $e = $this->seed();
        $books = $this->em->createQueryBuilder(LibAuthor::class)->relation('books');
        $books->of($e['cyd'])->add([$e['notes']]);
        $this->assertSame(['Notes'], self::pluck($this->em->createQueryBuilder()->relation(LibAuthor::class, 'books')->of(3)->loadMany(), 'title'));
        $this->em->createQueryBuilder()->relation(LibAuthor::class, 'books')->of($e['cyd'])->remove($e['notes']);
        $this->assertNull($this->conn->fetchColumn('SELECT author_id FROM lib_books WHERE id = 2'));

        $author = $this->em->createQueryBuilder()->relation(LibBook::class, 'author');
        $author->of([1, 2])->set($e['bob']);
        $this->assertSame(['2', '2', '2'], array_map('strval', $this->column('SELECT author_id FROM lib_books ORDER BY id')));
        $this->assertSame('Bob', $this->em->createQueryBuilder()->relation(LibBook::class, 'author')->of(1)->loadOne()->name);
        $this->em->createQueryBuilder()->relation(LibBook::class, 'author')->of(1)->set(null);
        $this->assertNull($this->em->createQueryBuilder()->relation(LibBook::class, 'author')->of(1)->loadOne());

        // inverse one-to-one: the previous bio is released first (unique foreign key)
        $bio = $this->em->save($this->em->create(LibBio::class, ['text' => 'Second']));
        $this->em->createQueryBuilder()->relation(LibAuthor::class, 'bio')->of($e['ada'])->set($bio);
        $this->assertSame('Second', $this->em->createQueryBuilder()->relation(LibAuthor::class, 'bio')->of($e['ada'])->loadOne()->text);
        $this->assertSame([null, 1], array_map(fn ($v) => $v === null ? null : (int) $v, $this->column('SELECT author_id FROM lib_bios ORDER BY id')));
        $this->em->createQueryBuilder()->relation(LibAuthor::class, 'bio')->of(1)->set(null);
        $this->assertNull($this->em->createQueryBuilder()->relation(LibAuthor::class, 'bio')->of(1)->loadOne());
    }

    public function testRelationQueryBuilderFollowsTypeOrmRules(): void
    {
        $this->seed();
        try {
            $this->em->createQueryBuilder()->relation(LibBook::class, 'genres')->of(1)->set([1]);
            $this->fail('expected an exception');
        } catch (OrmException $e) {
            $this->assertSame('Set operation is only supported for many-to-one and one-to-one relations. However given "genres" has many-to-many relation. Use .add() method instead.', $e->getMessage());
        }
        try {
            $this->em->createQueryBuilder()->relation(LibBook::class, 'author')->of(1)->add(1);
            $this->fail('expected an exception');
        } catch (OrmException $e) {
            $this->assertSame('Add operation is only supported for many-to-many and one-to-many relations. However given "author" has many-to-one relation. Use .set() method instead.', $e->getMessage());
        }
        try {
            $this->em->createQueryBuilder()->relation(LibAuthor::class, 'books')->of([1, 2])->add(3);
            $this->fail('expected an exception');
        } catch (OrmException $e) {
            $this->assertStringContainsString('Provide a single entity into "of" method.', $e->getMessage());
        }
        $this->expectException(OrmException::class);
        $this->expectExceptionMessage('Call of($entityOrId)');
        $this->em->createQueryBuilder()->relation(LibBook::class, 'genres')->loadMany();
    }
}
