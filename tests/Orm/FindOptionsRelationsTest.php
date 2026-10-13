<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\OrmException;
use Nesto\Tests\Orm\Fixtures\Library\LibAuthor;
use Nesto\Tests\Orm\Fixtures\Library\LibBook;
use Nesto\Tests\Orm\Fixtures\Library\LibChapter;
use Nesto\Tests\Orm\Fixtures\Library\LibGenre;

/** Find options on relations: relations (both forms), relationLoadStrategy, where / order / select on relations, relation ids. */
final class FindOptionsRelationsTest extends LibraryTestCase
{
    public function testRelationsAcceptTheNestedObjectForm(): void
    {
        $this->seed();
        $list = $this->em->find(LibAuthor::class, ['relations' => ['books', 'books.genres', 'bio']]);
        $nested = $this->em->find(LibAuthor::class, ['relations' => ['books' => ['genres' => true], 'bio' => true, 'books.chapters' => false]]);
        foreach ([$list, $nested] as $authors) {
            $this->assertSame(['Engines', 'Notes'], self::pluck($authors[0]->books, 'title'));
            $this->assertSame(['science', 'history'], self::pluck($authors[0]->books[0]->genres, 'name'));
            $this->assertSame('Countess', $authors[0]->bio->text);
            $this->assertNull($authors[1]->bio);
            $this->assertSame([], $authors[2]->books);
        }
    }

    public function testJoinStrategyLoadsEverythingInOneQuery(): void
    {
        $this->seed();
        $this->startCounting();
        $authors = $this->em->find(LibAuthor::class, ['relations' => ['books' => ['genres' => true], 'bio' => true], 'relationLoadStrategy' => 'join']);
        $this->assertCount(3, $this->queries(), 'one joined SELECT + the #[RelationId] queries of LibAuthor::$bookIds and LibBook::$genreIds');
        $this->assertSame(['Engines', 'Notes'], self::pluck($authors[0]->books, 'title'));
        $this->assertSame(['science', 'history'], self::pluck($authors[0]->books[0]->genres, 'name'));
        $this->assertSame('Countess', $authors[0]->bio->text);
        $this->assertSame([], $authors[2]->books);

        // pagination stays on root rows despite the to-many joins
        $page = $this->em->find(LibAuthor::class, ['relations' => ['books'], 'relationLoadStrategy' => 'join', 'take' => 2, 'order' => ['name' => 'ASC']]);
        $this->assertSame(['Ada', 'Bob'], self::pluck($page, 'name'));
        $this->assertSame(['Engines', 'Notes'], self::pluck($page[0]->books, 'title'));

        // the DataSource option sets the default
        $this->assertSame('query', $this->ds->getOptions()['relationLoadStrategy'] ?? 'query');
        $this->expectException(OrmException::class);
        $this->em->find(LibAuthor::class, ['relationLoadStrategy' => 'lazy']);
    }

    public function testWhereOnRelationColumns(): void
    {
        $this->seed();
        $this->assertSame(['Engines', 'Notes'], self::pluck($this->em->find(LibBook::class, ['where' => ['author' => ['name' => 'Ada']]]), 'title'));
        $this->assertSame(['E1', 'E2'], self::pluck($this->em->find(LibChapter::class, ['where' => ['book' => ['author' => ['rank' => ['>=', 2]]]]]), 'title'));
        $this->assertSame(['Bob'], self::pluck($this->em->find(LibAuthor::class, ['where' => ['books' => ['title' => 'Sailing']]]), 'name'));
        $this->assertSame(['Engines', 'Notes'], self::pluck($this->em->find(LibBook::class, ['where' => ['genres' => ['name' => 'science']]]), 'title'));
        $this->assertSame(['Notes', 'Sailing'], self::pluck($this->em->find(LibBook::class, ['where' => [['author' => ['name' => 'Bob']], ['title' => 'Notes']]]), 'title'));

        // to-many conditions do not duplicate or shrink pages, and count distinct roots
        $this->assertSame(['Ada'], self::pluck($this->em->find(LibAuthor::class, ['where' => ['books' => ['pages' => ['>', 100]]], 'take' => 1]), 'name'));
        $this->assertSame(2, $this->em->getRepository(LibAuthor::class)->count(['where' => ['books' => ['pages' => ['>', 100]]]]));
        $this->assertSame(1, $this->em->getRepository(LibGenre::class)->count(['where' => ['books' => ['author' => ['name' => 'Bob']]]]));
    }

    public function testJoinStrategyFiltersTheLoadedRelationLikeTypeOrm(): void
    {
        $this->seed();
        $where = ['books' => ['title' => 'Engines']];
        $joined = $this->em->findOneOrFail(LibAuthor::class, ['where' => $where, 'relations' => ['books'], 'relationLoadStrategy' => 'join']);
        $this->assertSame(['Engines'], self::pluck($joined->books, 'title'), 'the where join is the relation join');
        $queried = $this->em->findOneOrFail(LibAuthor::class, ['where' => $where, 'relations' => ['books']]);
        $this->assertSame(['Engines', 'Notes'], self::pluck($queried->books, 'title'));
    }

    public function testOrderByRelationColumns(): void
    {
        $this->seed();
        $this->assertSame(['Engines', 'Notes', 'Sailing'], self::pluck($this->em->find(LibBook::class, ['order' => ['author' => ['rank' => 'DESC'], 'title' => 'ASC']]), 'title'));
        $this->assertSame(['Sailing', 'Engines', 'Notes'], self::pluck($this->em->find(LibBook::class, ['order' => ['author' => ['rank' => 1], 'pages' => -1]]), 'title'));
        $this->assertSame(['Sailing', 'Engines', 'Notes'], self::pluck($this->em->find(LibBook::class, ['relations' => ['author'], 'relationLoadStrategy' => 'join', 'order' => ['author' => ['name' => ['direction' => 'desc']], 'pages' => 'DESC']]), 'title'));
    }

    public function testSelectOnRootAndRelationColumns(): void
    {
        $this->seed();
        foreach (['query', 'join'] as $strategy) {
            $this->startCounting();
            $books = $this->em->find(LibBook::class, ['select' => ['title' => true, 'author' => ['name' => true]], 'relationLoadStrategy' => $strategy]);
            $sql = implode("\n", $this->queries());
            $this->assertStringNotContainsString('"pages"', $sql, $strategy);
            $this->assertStringNotContainsString('"rank"', $sql, $strategy);
            $this->assertSame(['Engines', 'Notes', 'Sailing'], self::pluck($books, 'title'));
            $this->assertFalse((new \ReflectionProperty($books[0], 'pages'))->isInitialized($books[0]), 'not selected: not loaded');
            $this->assertSame('Ada', $books[0]->author->name);
            $this->assertFalse((new \ReflectionProperty($books[0]->author, 'rank'))->isInitialized($books[0]->author));
        }
        $partial = $this->em->findOneOrFail(LibBook::class, ['select' => ['id', 'title'], 'where' => ['title' => 'Notes']]);
        $partial->title = 'Notes II';
        $this->startCounting();
        $this->em->save($partial);
        $this->assertSame(['UPDATE "lib_books" SET "title" = :v0 WHERE "id" = :nesto_pk'], $this->queries(), 'the unselected columns are not written back');
        $this->assertSame(120, (int) $this->conn->fetchColumn('SELECT pages FROM lib_books WHERE id = 2'));
    }

    public function testRelationIdsAndLoadRelationIds(): void
    {
        $this->seed();
        $books = $this->em->find(LibBook::class);
        $this->assertSame([[1, [1, 2]], [1, [1]], [2, [3]]], array_map(fn (LibBook $b) => [$b->authorId, $b->genreIds], $books));
        $qb = $this->em->createQueryBuilder(LibAuthor::class, 'a')->leftJoinAndSelect('a.books', 'b')->orderBy('a.id')->getMany();
        $this->assertSame([[1, 2], [3], []], self::pluck($qb, 'bookIds'));
        $this->assertSame([1, 2], $qb[0]->books[0]->genreIds, '#[RelationId] of joined entities too');

        $withIds = $this->em->findOneOrFail(LibBook::class, ['where' => ['title' => 'Engines'], 'loadRelationIds' => ['relations' => ['genres', 'chapters']]]);
        $this->assertSame([1, 2], $withIds->genres);
        $this->assertSame([1, 2], $withIds->chapters);
        $maps = $this->em->findOneOrFail(LibBook::class, ['where' => ['title' => 'Engines'], 'loadRelationIds' => ['relations' => ['genres'], 'disableMixedMap' => true]]);
        $this->assertSame([['id' => 1], ['id' => 2]], $maps->genres);

        // ids stand for existing rows when saved back
        $withIds->genres = [2];
        $this->em->save($withIds);
        $this->assertSame(['2'], array_map('strval', $this->column('SELECT genre_id FROM lib_book_genres WHERE book_id = 1')));

        $this->expectException(OrmException::class);
        $this->expectExceptionMessage(LibBook::class . '::$author (?' . LibAuthor::class . ') cannot hold the relation id(s) 1: widen its type');
        $this->em->find(LibBook::class, ['loadRelationIds' => true]);
    }

    public function testUnknownRelationsAndOptions(): void
    {
        $this->expectException(OrmException::class);
        $this->expectExceptionMessage('Unknown relation "nope" on ' . LibBook::class);
        $this->em->find(LibBook::class, ['relations' => ['nope']]);
    }
}
