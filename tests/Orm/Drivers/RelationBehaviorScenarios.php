<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers;

use Nesto\Orm\DataSource;
use Nesto\Orm\QueryBuilder;
use Nesto\Tests\Orm\Fixtures\Library\LibAuthor;
use Nesto\Tests\Orm\Fixtures\Library\LibBio;
use Nesto\Tests\Orm\Fixtures\Library\LibBook;
use Nesto\Tests\Orm\Fixtures\Library\LibChapter;
use Nesto\Tests\Orm\Fixtures\Library\LibGenre;
use Nesto\Tests\Orm\Fixtures\Library\LibrarySeeder;
use Nesto\Tests\Orm\Fixtures\Library\LibReview;

/**
 * Relation behaviour (cascades, orphans, join tables, find options on relations, relation query builder)
 * on every driver, with the Library fixtures (tables lib_*, dropped before and after each scenario).
 * Used by DriverTestCase.
 */
trait RelationBehaviorScenarios
{
    private ?DataSource $library = null;

    private function libraryDataSource(): DataSource
    {
        $ds = new DataSource(['entities' => LibrarySeeder::ENTITIES]);
        $ds->useConnection($this->ds->getConnection());
        $ds->synchronizer()->drop(LibrarySeeder::ENTITIES);
        $ds->synchronize();

        return $this->library = $ds;
    }

    #[\PHPUnit\Framework\Attributes\After]
    protected function dropLibraryTables(): void
    {
        if ($this->library !== null) {
            $this->library->synchronizer()->drop(LibrarySeeder::ENTITIES);
            $this->library = null;
        }
    }

    private function qi(string $identifier): string
    {
        return $this->ds->getConnection()->quote($identifier);
    }

    /** @return list<mixed> */
    private function col(string $sql, array $params = []): array
    {
        $sql = (string) preg_replace_callback('/`(\w+)`/', fn ($m) => $this->qi($m[1]), $sql);

        return array_map(fn (array $row) => ($v = reset($row)) === null ? null : (is_numeric($v) ? (int) $v : $v), $this->ds->getConnection()->fetchAll($sql, $params));
    }

    public function testRelationCascadesOrphansAndJoinTables(): void
    {
        $em = $this->libraryDataSource()->getEntityManager();
        $e = LibrarySeeder::seed($em);
        $conn = $em->getConnection();

        // one save per author inserted the whole graph, parents first
        $this->assertSame([1, 1, 2], $this->col('SELECT `author_id` FROM `lib_books` ORDER BY `id`'));
        $this->assertSame(['1-1', '1-2', '2-1', '3-3'], array_map(fn (array $r) => $r['b'] . '-' . $r['g'], $conn->fetchAll(
            'SELECT ' . $this->qi('book_id') . ' AS b, ' . $this->qi('genre_id') . ' AS g FROM ' . $this->qi('lib_book_genres') . ' ORDER BY b, g',
        )));
        $this->assertSame([1], $this->col('SELECT `author_id` FROM `lib_bios`'));

        // cascade update + unchanged graph writes nothing
        $ada = $em->findOneOrFail(LibAuthor::class, ['where' => ['name' => 'Ada'], 'relations' => ['books.genres', 'bio']]);
        $ada->books[1]->pages = 121;
        $em->save($ada);
        $this->assertSame([121], $this->col('SELECT `pages` FROM `lib_books` WHERE `id` = 2'));
        $conn->enableQueryLog()->flushQueryLog();
        $em->save($ada);
        $this->assertSame(0, $conn->queryCount());
        $conn->disableQueryLog();

        // orphanedRowAction: delete / soft-delete / nullify (loaded ids and NOT IN fallback)
        $engines = $em->findOneOrFail(LibBook::class, ['where' => ['title' => 'Engines'], 'relations' => ['chapters', 'reviews']]);
        $engines->chapters = [$engines->chapters[1]];
        $engines->reviews = [];
        $em->save($engines);
        $this->assertSame(['E2'], $this->col('SELECT `title` FROM `lib_chapters`'));
        $this->assertSame(2, (int) $conn->fetchColumn('SELECT COUNT(*) FROM ' . $this->qi('lib_reviews') . ' WHERE ' . $this->qi('deleted_at') . ' IS NOT NULL'));
        $this->assertSame([], $em->find(LibReview::class));
        $ada->books = [$ada->books[0]];
        $em->save($ada);
        $this->assertSame([1, null, 2], $this->col('SELECT `author_id` FROM `lib_books` ORDER BY `id`'));
        $bob = $em->findOneOrFail(LibAuthor::class, ['where' => ['name' => 'Bob']]);
        $bob->books = [];
        $em->save($bob);
        $this->assertSame([1, null, null], $this->col('SELECT `author_id` FROM `lib_books` ORDER BY `id`'));

        // join table diff from the inverse side, unknown previous rows read first
        $travel = $em->findOneOrFail(LibGenre::class, ['where' => ['name' => 'travel']]);
        $travel->books = [$e['engines'], $e['notes']];
        $em->save($travel);
        $this->assertSame([1, 2], $this->col('SELECT `book_id` FROM `lib_book_genres` WHERE `genre_id` = 3 ORDER BY `book_id`'));

        // inverse one-to-one replaced: the old row releases its unique key first
        $ada->bio = $em->create(LibBio::class, ['text' => 'Second']);
        $em->save($ada);
        $this->assertSame([null, 1], $this->col('SELECT `author_id` FROM `lib_bios` ORDER BY `id`'));

        // FK cycle between two new rows: inserted, then the deferred key is written
        $eve = $em->create(LibAuthor::class, ['name' => 'Eve']);
        $loop = $em->create(LibBook::class, ['title' => 'Loop']);
        $eve->favoriteBook = $loop;
        $loop->author = $eve;
        $em->save($eve);
        $this->assertSame([$eve->id], $this->col('SELECT `author_id` FROM `lib_books` WHERE `id` = :id', ['id' => $loop->id]));

        // softRemove / recover cascade, then remove cascades children before the parent
        // (LibBio has no DeleteDateColumn: cascading soft-remove to a loaded bio would throw, like TypeORM)
        $ada = $em->findOneOrFail(LibAuthor::class, ['where' => ['name' => 'Ada'], 'relations' => ['books']]);
        $em->softRemove($ada);
        $this->assertSame(['Bob', 'Cyd', 'Eve'], array_map(fn (LibAuthor $a) => $a->name, $em->find(LibAuthor::class)));
        $this->assertNull($em->findOne(LibBook::class, ['where' => ['title' => 'Engines']]));
        $em->recover($ada);
        $this->assertSame('Engines', $em->findOneOrFail(LibBook::class, ['where' => ['title' => 'Engines']])->title);
        $em->remove($em->findOneOrFail(LibAuthor::class, ['where' => ['name' => 'Ada'], 'relations' => ['books', 'bio']]));
        $this->assertSame([], $this->col('SELECT `id` FROM `lib_books` WHERE `title` = :t', ['t' => 'Engines']));
        $this->assertSame(['Countess'], $this->col('SELECT `text` FROM `lib_bios`'), 'cascade remove of the loaded bio (the released one stays)');
        $this->assertSame([], $this->col('SELECT `book_id` FROM `lib_book_genres` WHERE `book_id` = 1'));
        $this->assertSame(['Bob', 'Cyd', 'Eve'], $this->col('SELECT `name` FROM `lib_authors` ORDER BY `id`'));
    }

    public function testRelationFindOptionsAndRelationQueryBuilder(): void
    {
        $em = $this->libraryDataSource()->getEntityManager();
        $e = LibrarySeeder::seed($em);
        $titles = fn (array $books) => array_map(fn (LibBook $b) => $b->title, $books);
        $names = fn (iterable $list) => array_map(fn (object $o) => $o->name, is_array($list) ? $list : iterator_to_array($list));

        // where / order on relation columns, to-many conditions with pagination and counts
        $this->assertSame(['Engines', 'Notes'], $titles($em->find(LibBook::class, ['where' => ['author' => ['name' => 'Ada']]])));
        $this->assertSame(['E1', 'E2'], array_map(fn (LibChapter $c) => $c->title, $em->find(LibChapter::class, ['where' => ['book' => ['author' => ['rank' => ['>=', 2]]]]])));
        $this->assertSame(['Ada'], $names($em->find(LibAuthor::class, ['where' => ['books' => ['pages' => ['>', 100]]], 'take' => 1])));
        $this->assertSame(2, $em->count(LibAuthor::class, ['where' => ['books' => ['pages' => ['>', 100]]]]));
        $this->assertSame(['Sailing', 'Engines', 'Notes'], $titles($em->find(LibBook::class, ['order' => ['author' => ['rank' => 'ASC'], 'pages' => 'DESC']])));

        // both load strategies, join + pagination on to-many joins, partial selects
        foreach (['query', 'join'] as $strategy) {
            $authors = $em->find(LibAuthor::class, ['relations' => ['books' => ['genres' => true], 'bio' => true], 'relationLoadStrategy' => $strategy, 'order' => ['name' => 'DESC'], 'take' => 2, 'skip' => 1]);
            $this->assertSame(['Bob', 'Ada'], $names($authors), $strategy);
            $this->assertSame(['Engines', 'Notes'], $titles($authors[1]->books), $strategy);
            $this->assertSame(['science', 'history'], $names($authors[1]->books[0]->genres), $strategy);
            $this->assertSame('Countess', $authors[1]->bio->text, $strategy);
            $books = $em->find(LibBook::class, ['select' => ['title' => true, 'author' => ['name' => true]], 'relationLoadStrategy' => $strategy]);
            $this->assertSame(['Engines', 'Notes', 'Sailing'], $titles($books), $strategy);
            $this->assertSame('Bob', $books[2]->author->name, $strategy);
            $this->assertFalse((new \ReflectionProperty($books[2], 'pages'))->isInitialized($books[2]), $strategy);
        }

        // relation ids
        $this->assertSame([[1, [1, 2]], [1, [1]], [2, [3]]], array_map(fn (LibBook $b) => [$b->authorId, $b->genreIds], $em->find(LibBook::class)));
        $this->assertSame([1, 2], $em->findOneOrFail(LibBook::class, ['where' => ['id' => 1], 'loadRelationIds' => ['relations' => ['genres']]])->genres);

        // query builder: map one / many, counts, ids
        $authors = $em->createQueryBuilder(LibAuthor::class, 'a')
            ->leftJoinAndMapOne('a.longestBook', 'a.books', 'lb', 'lb.pages > :min', ['min' => 250])
            ->leftJoinAndMapMany('a.longBooks', LibBook::class, 'b2', 'b2.author = a.id AND b2.pages >= :p', ['p' => 200])
            ->loadRelationCountAndMap('a.bookCount', 'a.books')
            ->loadRelationIdAndMap('a.bookIdList', 'a.books', 'bk', fn (QueryBuilder $qb) => $qb->andWhere('bk.pages > :pp', ['pp' => 150]))
            ->orderBy('a.id')
            ->getMany();
        $this->assertSame('Engines', $authors[0]->longestBook->title);
        $this->assertSame(['Sailing'], $titles($authors[1]->longBooks));
        $this->assertSame([2, 1, 0], array_map(fn (LibAuthor $a) => $a->bookCount, $authors));
        $this->assertSame([[1], [3], []], array_map(fn (LibAuthor $a) => $a->bookIdList, $authors));

        // relation query builder
        $genres = fn () => $em->createQueryBuilder()->relation(LibBook::class, 'genres');
        $genres()->of($e['sailing'])->add([$e['poetry'], 2]);
        $genres()->of(3)->addAndRemove([1], [$e['travel']]);
        $this->assertSame(['science', 'history', 'poetry'], $names($genres()->of(3)->loadMany()));
        $em->createQueryBuilder()->relation(LibAuthor::class, 'books')->of($e['cyd'])->add($e['notes']);
        $em->createQueryBuilder()->relation(LibBook::class, 'author')->of([1])->set($e['bob']);
        $this->assertSame([2, 3, 2], $this->col('SELECT `author_id` FROM `lib_books` ORDER BY `id`'));
        $this->assertSame('Cyd', $em->createQueryBuilder()->relation(LibBook::class, 'author')->of(2)->loadOne()->name);
        $bio = $em->save($em->create(LibBio::class, ['text' => 'Second']));
        $em->createQueryBuilder()->relation(LibAuthor::class, 'bio')->of(1)->set($bio);
        $this->assertSame([null, 1], $this->col('SELECT `author_id` FROM `lib_bios` ORDER BY `id`'));
    }
}
