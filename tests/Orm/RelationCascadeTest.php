<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\OrmException;
use Nesto\Tests\Orm\Fixtures\Library\LibAuthor;
use Nesto\Tests\Orm\Fixtures\Library\LibBio;
use Nesto\Tests\Orm\Fixtures\Library\LibBook;
use Nesto\Tests\Orm\Fixtures\Library\LibChapter;
use Nesto\Tests\Orm\Fixtures\Library\LibGenre;
use Nesto\Tests\Orm\Fixtures\Library\LibNote;
use Nesto\Tests\Orm\Fixtures\Library\LibReview;

/** save() / remove() / softRemove() / recover() cascades and orphanedRowAction (TypeORM semantics). */
final class RelationCascadeTest extends LibraryTestCase
{
    public function testSaveCascadesInsertsThroughEveryRelationType(): void
    {
        $e = $this->seed();
        $this->assertSame([[1, 'Ada', 1], [2, 'Bob', null], [3, 'Cyd', null]], array_map(fn (array $r) => [(int) $r['id'], $r['name'], $r['bio'] === null ? null : (int) $r['bio']], $this->conn->fetchAll(
            'SELECT a.id, a.name, b.id AS bio FROM lib_authors a LEFT JOIN lib_bios b ON b.author_id = a.id ORDER BY a.id',
        )));
        $this->assertSame([['Engines', 1], ['Notes', 1], ['Sailing', 2]], array_map(fn (array $r) => [$r['title'], (int) $r['author_id']], $this->conn->fetchAll('SELECT title, author_id FROM lib_books ORDER BY id')));
        $this->assertSame(['1-1', '1-2', '2-1', '3-3'], array_map(fn (array $r) => $r['book_id'] . '-' . $r['genre_id'], $this->conn->fetchAll('SELECT book_id, genre_id FROM lib_book_genres ORDER BY book_id, genre_id')));
        $this->assertSame(['E1', 'E2'], $this->column('SELECT title FROM lib_chapters WHERE book_id = :id ORDER BY id', ['id' => $e['engines']->id]));
        $this->assertSame(4, $e['poetry']->id);
        $this->assertSame([1, 2], $this->em->findOneOrFail(LibAuthor::class, ['where' => ['name' => 'Ada']])->bookIds);
    }

    public function testNewRelatedEntitiesNeedCascadeInsert(): void
    {
        $genre = $this->em->create(LibGenre::class, ['name' => 'drama']);
        $genre->books = [$this->em->create(LibBook::class, ['title' => 'Unsaved'])];
        try {
            $this->em->save($genre);
            $this->fail('expected an exception');
        } catch (OrmException $e) {
            $this->assertStringContainsString(LibGenre::class . '::$books holds a new ' . LibBook::class . ' that is not saved', $e->getMessage());
            $this->assertStringContainsString("cascade: ['insert']", $e->getMessage());
        }
        $this->assertSame(0, (int) $this->conn->fetchColumn('SELECT COUNT(*) FROM lib_genres'), 'rolled back');

        $chapter = $this->em->create(LibChapter::class, ['title' => 'Loose']);
        $chapter->book = $this->em->create(LibBook::class, ['title' => 'Unsaved']);
        $this->expectException(OrmException::class);
        $this->expectExceptionMessage(LibChapter::class . '::$book holds a new ' . LibBook::class);
        $this->em->save($chapter);
    }

    public function testCascadeUpdateSavesChangedRelatedEntities(): void
    {
        $this->seed();
        $ada = $this->em->findOneOrFail(LibAuthor::class, ['where' => ['name' => 'Ada'], 'relations' => ['books.genres', 'bio']]);
        $ada->books[0]->title = 'Engines II';      // LibAuthor::$books cascades update
        $ada->books[0]->genres[0]->name = 'sci';  // LibBook::$genres cascades insert only
        $ada->bio->text = 'Lady';
        $this->startCounting();
        $this->em->save($ada);
        $this->assertCount(2, $this->queries(), 'one UPDATE per changed entity, nothing else');
        $this->assertSame(['Engines II', 'Notes', 'Sailing'], $this->column('SELECT title FROM lib_books ORDER BY id'));
        $this->assertSame('science', $this->conn->fetchColumn('SELECT name FROM lib_genres WHERE id = 1'));
        $this->assertSame('Lady', $this->conn->fetchColumn('SELECT text FROM lib_bios'));

        $this->startCounting();
        $this->em->save($ada);
        $this->assertSame([], $this->queries(), 'unchanged graph: no queries');
    }

    public function testWithoutCascadeUpdateOnlyTheForeignKeyIsWritten(): void
    {
        $this->seed();
        $notes = $this->em->findOneOrFail(LibBook::class, ['where' => ['title' => 'Notes'], 'relations' => ['genres']]);
        $travel = $this->em->findOneOrFail(LibGenre::class, ['where' => ['name' => 'travel'], 'relations' => ['books']]);
        $notes->title = 'Not saved through the genre';
        $travel->books = [...$travel->books, $notes];   // inverse ManyToMany without cascade: join rows only
        $this->em->save($travel);
        $this->assertSame('Notes', $this->conn->fetchColumn('SELECT title FROM lib_books WHERE id = :id', ['id' => $notes->id]));
        $this->assertSame([2, 3], array_map('intval', $this->column('SELECT book_id FROM lib_book_genres WHERE genre_id = 3 ORDER BY book_id')));
    }

    public function testOrphanedRowActions(): void
    {
        $this->seed();
        $engines = $this->em->findOneOrFail(LibBook::class, ['where' => ['title' => 'Engines'], 'relations' => ['chapters', 'reviews', 'notes']]);
        $engines->chapters = [$engines->chapters[0]];   // orphanedRowAction: delete
        $engines->reviews = [$engines->reviews[1]];     // soft-delete
        $engines->notes = [];                           // disable
        $this->em->save($engines);
        $this->assertSame(['E1'], $this->column('SELECT title FROM lib_chapters ORDER BY id'));
        $this->assertSame([['R1', true, 1], ['R2', false, 1]], array_map(fn (array $r) => [$r['body'], $r['deleted_at'] !== null, (int) $r['book_id']], $this->conn->fetchAll('SELECT body, deleted_at, book_id FROM lib_reviews ORDER BY id')));
        $this->assertSame(['1', '1'], array_map('strval', $this->column('SELECT book_id FROM lib_notes ORDER BY id')));

        // nullify (default): the removed book stays, without author
        $ada = $this->em->findOneOrFail(LibAuthor::class, ['where' => ['name' => 'Ada'], 'relations' => ['books']]);
        $ada->books = [$ada->books[1]];
        $this->startCounting();
        $this->em->save($ada);
        $this->assertCount(1, $this->queries(), 'one UPDATE ... SET author_id = NULL WHERE author_id = ? AND id IN (removed)');
        $this->assertStringContainsString('SET "author_id" = NULL', $this->queries()[0]);
        $this->assertSame([null, 1, 2], array_map(fn ($v) => $v === null ? null : (int) $v, $this->column('SELECT author_id FROM lib_books ORDER BY id')));
    }

    public function testOrphansAreFoundInTheDatabaseWhenTheCollectionWasNotLoaded(): void
    {
        $this->seed();
        $ada = $this->em->findOneOrFail(LibAuthor::class, ['where' => ['name' => 'Ada']]);
        $notes = $this->em->findOneOrFail(LibBook::class, ['where' => ['title' => 'Notes']]);
        $ada->books = [$notes];   // never loaded: every other book pointing at Ada is an orphan (TypeORM)
        $this->em->save($ada);
        $this->assertSame([null, 1, 2], array_map(fn ($v) => $v === null ? null : (int) $v, $this->column('SELECT author_id FROM lib_books ORDER BY id')));
    }

    public function testMovingAChildToAnotherParent(): void
    {
        $this->seed();
        $bob = $this->em->findOneOrFail(LibAuthor::class, ['where' => ['name' => 'Bob'], 'relations' => ['books']]);
        $notes = $this->em->findOneOrFail(LibBook::class, ['where' => ['title' => 'Notes']]);
        $bob->books[] = $notes;
        $this->em->save($bob);
        $this->assertSame([1, 2, 2], array_map('intval', $this->column('SELECT author_id FROM lib_books ORDER BY id')));
        $this->assertSame([2, 3], $this->em->findOneOrFail(LibAuthor::class, ['where' => ['name' => 'Bob']])->bookIds);
        $this->assertSame([1], $this->em->findOneOrFail(LibAuthor::class, ['where' => ['name' => 'Ada']])->bookIds);
    }

    public function testInverseOneToOneNullAndReplace(): void
    {
        $this->seed();
        $ada = $this->em->findOneOrFail(LibAuthor::class, ['where' => ['name' => 'Ada'], 'relations' => ['bio']]);
        $ada->bio = $this->em->create(LibBio::class, ['text' => 'New']);
        $this->em->save($ada);
        $this->assertSame([['Countess', null], ['New', 1]], array_map(fn (array $r) => [$r['text'], $r['author_id'] === null ? null : (int) $r['author_id']], $this->conn->fetchAll('SELECT text, author_id FROM lib_bios ORDER BY id')));

        $ada->bio = null;
        $this->em->save($ada);
        $this->assertSame(0, (int) $this->conn->fetchColumn('SELECT COUNT(*) FROM lib_bios WHERE author_id IS NOT NULL'));
    }

    public function testUnloadedRelationsKeepTheirRows(): void
    {
        $this->seed();
        $ada = $this->em->findOneOrFail(LibAuthor::class, ['where' => ['name' => 'Ada']]);
        $this->assertNull($ada->bio, '`= null` default: not loaded');
        $this->startCounting();
        $this->em->save($ada);
        $this->assertSame([], $this->queries());
        $this->assertSame(1, (int) $this->conn->fetchColumn('SELECT author_id FROM lib_bios'));
    }

    public function testBidirectionalGraphIsSavedOnce(): void
    {
        $author = $this->em->create(LibAuthor::class, ['name' => 'Eve']);
        $book = $this->em->create(LibBook::class, ['title' => 'Loop']);
        $book->author = $author;
        $author->books = [$book];
        $this->startCounting();
        $this->em->save($book);   // book → author (cascade insert) → author.books contains the book being saved
        $this->assertSame([['Loop', 1]], array_map(fn (array $r) => [$r['title'], (int) $r['author_id']], $this->conn->fetchAll('SELECT title, author_id FROM lib_books')));
        $this->assertCount(2, array_filter($this->queries(), fn (string $sql) => str_starts_with($sql, 'INSERT')));
    }

    public function testForeignKeyCycleIsWrittenAfterBothInserts(): void
    {
        $author = $this->em->create(LibAuthor::class, ['name' => 'Eve']);
        $book = $this->em->create(LibBook::class, ['title' => 'Loop']);
        $author->favoriteBook = $book;   // author → book (cascade insert)
        $book->author = $author;          // book → author: the author has no id yet
        $this->em->save($author);
        $this->assertSame(['favorite' => $book->id, 'author' => $author->id], [
            'favorite' => (int) $this->conn->fetchColumn('SELECT favorite_book_id FROM lib_authors'),
            'author' => (int) $this->conn->fetchColumn('SELECT author_id FROM lib_books'),
        ]);
        $this->startCounting();
        $this->em->save($author);
        $this->assertSame([], $this->queries());
    }

    public function testManyToManyIsDiffedFromEitherSide(): void
    {
        $e = $this->seed();
        $science = $this->em->findOneOrFail(LibGenre::class, ['where' => ['name' => 'science'], 'relations' => ['books']]);
        $this->assertSame(['Engines', 'Notes'], self::pluck($science->books, 'title'));
        $science->books = [$science->books[1], $this->em->findOneOrFail(LibBook::class, ['where' => ['title' => 'Sailing']])];
        $this->startCounting();
        $this->em->save($science);
        $this->assertSame(['DELETE', 'INSERT'], array_map(fn (string $sql) => strtok($sql, ' '), $this->queries()), 'only the removed and the added pair');
        $this->assertSame(['1-2', '2-1', '3-1', '3-3'], array_map(fn (array $r) => $r['book_id'] . '-' . $r['genre_id'], $this->conn->fetchAll('SELECT book_id, genre_id FROM lib_book_genres ORDER BY book_id, genre_id')));

        // both sides in one save: the pair is written once
        $book = $this->em->create(LibBook::class, ['title' => 'Both']);
        $poetry = $this->em->findOneOrFail(LibGenre::class, ['where' => ['name' => 'poetry'], 'relations' => ['books']]);
        $book->genres = [$poetry];
        $poetry->books = [$book];
        $this->em->save([$book, $poetry]);
        $this->assertSame(1, (int) $this->conn->fetchColumn('SELECT COUNT(*) FROM lib_book_genres WHERE genre_id = :g', ['g' => $poetry->id]));

        // not loaded: the current rows are read and diffed (other columns / rows untouched)
        $sailing = $this->em->findOneOrFail(LibBook::class, ['where' => ['title' => 'Sailing']]);
        $sailing->genres = [$e['travel'], $e['history']];
        $this->startCounting();
        $this->em->save($sailing);
        $this->assertSame(['SELECT', 'DELETE', 'INSERT'], array_map(fn (string $sql) => strtok($sql, ' '), $this->queries()), 'science (added above) removed, history added, travel kept');
        $this->assertSame(['2', '3'], array_map('strval', $this->column('SELECT genre_id FROM lib_book_genres WHERE book_id = 3 ORDER BY genre_id')));
    }

    public function testRemoveCascades(): void
    {
        $this->seed();
        $ada = $this->em->findOneOrFail(LibAuthor::class, ['where' => ['name' => 'Ada'], 'relations' => ['books', 'bio']]);
        $this->startCounting();
        $this->em->remove($ada);
        $deletes = array_values(array_filter($this->queries(), fn (string $sql) => str_starts_with($sql, 'DELETE')));
        $this->assertSame(['lib_book_genres', 'lib_books', 'lib_book_genres', 'lib_books', 'lib_bios', 'lib_authors'], array_map(fn (string $sql) => preg_match('/FROM "(\w+)"/', $sql, $m) ? $m[1] : $sql, $deletes), 'children (and their join rows) before the parent');
        $this->assertSame(['Sailing'], $this->column('SELECT title FROM lib_books'));
        $this->assertSame(['3-3'], array_map(fn (array $r) => $r['book_id'] . '-' . $r['genre_id'], $this->conn->fetchAll('SELECT book_id, genre_id FROM lib_book_genres')));
        $this->assertSame(['Bob', 'Cyd'], $this->column('SELECT name FROM lib_authors ORDER BY id'));
        $this->assertSame(0, (int) $this->conn->fetchColumn('SELECT COUNT(*) FROM lib_chapters'), 'DB ON DELETE CASCADE for what was not loaded');

        // no cascade "remove" on LibBook::$author: the author stays
        $sailing = $this->em->findOneOrFail(LibBook::class, ['where' => ['title' => 'Sailing'], 'relations' => ['author']]);
        $this->em->remove($sailing);
        $this->assertSame(['Bob', 'Cyd'], $this->column('SELECT name FROM lib_authors ORDER BY id'));
    }

    public function testSoftRemoveAndRecoverCascade(): void
    {
        $this->seed();
        $ada = $this->em->findOneOrFail(LibAuthor::class, ['where' => ['name' => 'Ada'], 'relations' => ['books']]);
        $this->em->softRemove($ada);
        $this->assertNotNull($ada->deletedAt);
        $this->assertNotNull($ada->books[0]->deletedAt);
        $this->assertSame(['Sailing'], self::pluck($this->em->find(LibBook::class), 'title'));
        $this->assertSame(['Bob', 'Cyd'], self::pluck($this->em->find(LibAuthor::class), 'name'));

        $this->em->recover($ada);
        $this->assertNull($ada->deletedAt);
        $this->assertSame(['Engines', 'Notes', 'Sailing'], self::pluck($this->em->find(LibBook::class), 'title'));
        $this->assertSame(['Ada', 'Bob', 'Cyd'], self::pluck($this->em->find(LibAuthor::class), 'name'));

        $this->expectException(OrmException::class);
        $this->expectExceptionMessage(LibChapter::class . ' has no #[DeleteDateColumn]: softRemove() needs one.');
        $this->em->softRemove($this->em->findOneOrFail(LibChapter::class));
    }

    public function testAppendingToALoadedCollectionOnlyInserts(): void
    {
        $this->seed();
        $engines = $this->em->findOneOrFail(LibBook::class, ['where' => ['title' => 'Engines'], 'relations' => ['notes', 'reviews']]);
        $engines->notes[] = $this->em->create(LibNote::class, ['text' => 'N3']);
        $engines->reviews[] = $this->em->create(LibReview::class, ['body' => 'R3']);
        $this->startCounting();
        $this->em->save($engines);
        $this->assertSame(['INSERT', 'INSERT'], array_map(fn (string $sql) => strtok($sql, ' '), $this->queries()));
        $this->assertSame(['N1', 'N2', 'N3'], $this->column('SELECT text FROM lib_notes WHERE book_id = 1 ORDER BY id'));
        $this->assertSame(0, (int) $this->conn->fetchColumn('SELECT COUNT(*) FROM lib_reviews WHERE deleted_at IS NOT NULL'));
    }
}
