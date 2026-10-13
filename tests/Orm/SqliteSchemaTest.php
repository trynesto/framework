<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\DataSource;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\Migration\Blueprint;
use Nesto\Orm\Migration\Migration;
use Nesto\Orm\Migration\Schema;
use Nesto\Orm\OrmException;
use Nesto\Orm\QueryException;
use PHPUnit\Framework\TestCase;

/** SQLite ALTERs that need a table rebuild: dropping indexed / constrained columns, adding foreign keys. */
final class SqliteSchemaTest extends TestCase
{
    private DataSource $ds;
    private Schema $schema;

    protected function setUp(): void
    {
        MetadataRegistry::clear();
        $this->ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:']);
        $this->ds->initialize();
        $this->schema = $this->ds->schema();
        $this->schema->createTable('authors', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        $this->schema->createTable('books', function (Blueprint $t): void {
            $t->id();
            $t->string('title')->index();
            $t->string('isbn')->unique();
            $t->integer('pages')->default(0);
            $t->bigInteger('author_id')->nullable();
            $t->foreignId('editor_id')->nullable()->constrained('authors')->nullOnDelete();
            $t->index(['title', 'pages']);
            $t->index('pages', 'idx_pages');
        });
        $this->exec("INSERT INTO authors (id, name) VALUES (1, 'Ada'), (2, 'Bob')");
        $this->exec("INSERT INTO books (id, title, isbn, pages, author_id, editor_id) VALUES (1, 'A', 'i-1', 10, 1, 2), (2, 'B', 'i-2', 20, 2, NULL)");
    }

    public function testDropIndexedColumn(): void
    {
        $this->schema->dropColumn('books', 'title');
        $this->assertSame(['id', 'isbn', 'pages', 'author_id', 'editor_id'], $this->schema->columns('books'));
        $this->assertSame(['idx_pages'], $this->indexes('books'), 'indexes on other columns survive');
        $this->assertSame([[1, 'i-1'], [2, 'i-2']], $this->rows('SELECT id, isbn FROM books ORDER BY id'));
    }

    public function testDropColumnWithInlineUniqueAndForeignKeyRebuildsTheTable(): void
    {
        $this->schema->createTable('reviews', function (Blueprint $t): void {
            $t->id();
            $t->string('code')->unique();
            $t->foreignId('book_id')->constrained()->cascadeOnDelete();
            $t->string('body');
        });
        $this->exec("INSERT INTO reviews (code, book_id, body) VALUES ('r1', 1, 'nice'), ('r2', 2, 'meh')");
        $this->exec('DELETE FROM reviews WHERE id = 2');

        $this->schema->dropColumn('reviews', 'code', 'book_id');
        $this->assertSame(['id', 'body'], $this->schema->columns('reviews'));
        $this->assertSame([], $this->conn()->fetchAll('PRAGMA foreign_key_list("reviews")'));
        $this->assertSame([[1, 'nice']], $this->rows('SELECT id, body FROM reviews'));
        $this->conn()->execute("INSERT INTO reviews (body) VALUES ('next')");
        $this->assertSame(3, (int) $this->conn()->fetchColumn("SELECT id FROM reviews WHERE body = 'next'"), 'AUTOINCREMENT sequence kept');
        $this->assertSame(1, (int) $this->conn()->fetchColumn('PRAGMA foreign_keys'), 'enforcement restored');
    }

    public function testDropColumnOfATableOthersReferenceKeepsTheirRows(): void
    {
        $this->schema->createTable('chapters', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('book_id')->constrained()->cascadeOnDelete();
        });
        $this->exec('INSERT INTO chapters (book_id) VALUES (1), (2)');
        $this->schema->table('books', fn (Blueprint $t) => $t->string('blurb')->nullable()->unique());
        $this->schema->dropColumn('books', 'blurb');
        $this->assertSame(2, (int) $this->conn()->fetchColumn('SELECT COUNT(*) FROM chapters'), 'no cascade from the rebuild');

        $this->exec('DELETE FROM books WHERE id = 1');
        $this->assertSame(1, (int) $this->conn()->fetchColumn('SELECT COUNT(*) FROM chapters'), 'the reference still points at the rebuilt table');
    }

    public function testAddForeignKeyToAnExistingColumn(): void
    {
        $this->schema->table('books', fn (Blueprint $t) => $t->foreign('author_id')->references('id')->on('authors')->cascadeOnDelete());

        $fks = array_column($this->conn()->fetchAll('PRAGMA foreign_key_list("books")'), 'table', 'from');
        $this->assertSame(['author_id' => 'authors', 'editor_id' => 'authors'], $this->sorted($fks));
        $this->assertSame(['idx_books_title', 'idx_books_title_pages', 'idx_pages'], $this->indexes('books'));
        try {
            $this->exec("INSERT INTO books (title, isbn) VALUES ('C', 'i-1')");
            $this->fail('inline UNIQUE survives the rebuild');
        } catch (QueryException) {
        }
        try {
            $this->exec("INSERT INTO books (title, isbn, author_id) VALUES ('C', 'i-3', 99)");
            $this->fail('the new foreign key is enforced');
        } catch (QueryException) {
        }
        $this->exec('DELETE FROM authors WHERE id = 1');
        $this->assertSame([[2, 'B']], $this->rows('SELECT id, title FROM books'), 'ON DELETE CASCADE applies');
    }

    public function testAddForeignIdColumnToAnExistingTable(): void
    {
        $this->schema->table('books', fn (Blueprint $t) => $t->foreignId('publisher_id')->nullable()->constrained('authors')->nullOnDelete());
        $this->assertContains('publisher_id', $this->schema->columns('books'));
        $fks = array_column($this->conn()->fetchAll('PRAGMA foreign_key_list("books")'), 'on_delete', 'from');
        $this->assertSame('SET NULL', $fks['publisher_id']);
    }

    public function testAddingAForeignKeyThatExistingRowsViolateFailsAndChangesNothing(): void
    {
        $this->exec('UPDATE books SET author_id = 42 WHERE id = 2');
        try {
            $this->schema->table('books', fn (Blueprint $t) => $t->foreign('author_id')->on('authors'));
            $this->fail('violating rows must be reported');
        } catch (OrmException $e) {
            $this->assertStringContainsString('books', $e->getMessage());
        }
        $this->assertSame(['editor_id'], array_column($this->conn()->fetchAll('PRAGMA foreign_key_list("books")'), 'from'));
        $this->assertSame(2, (int) $this->conn()->fetchColumn('SELECT COUNT(*) FROM books'));
        $this->assertSame(1, (int) $this->conn()->fetchColumn('PRAGMA foreign_keys'));
    }

    public function testRebuildInsideAMigrationTransaction(): void
    {
        $this->schema->createTable('chapters', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('book_id')->constrained()->cascadeOnDelete();
        });
        $this->exec('INSERT INTO chapters (book_id) VALUES (1), (2)');
        $ds = new DataSource(['migrations' => [new SqliteRebuildMigration]]);
        $ds->useConnection($this->conn());
        $ds->runMigrations();

        $this->assertNotContains('isbn', $this->schema->columns('books'));
        $this->assertContains('author_id', array_column($this->conn()->fetchAll('PRAGMA foreign_key_list("books")'), 'from'));
        $this->assertSame(2, (int) $this->conn()->fetchColumn('SELECT COUNT(*) FROM chapters'));
        $this->assertSame(1, (int) $this->conn()->fetchColumn('PRAGMA foreign_keys'));
    }

    public function testMigrationLeavingBrokenReferencesIsRolledBack(): void
    {
        $ds = new DataSource(['migrations' => [new SqliteBrokenReferenceMigration]]);
        $ds->useConnection($this->conn());
        try {
            $ds->runMigrations();
            $this->fail('violations must be reported');
        } catch (OrmException $e) {
            $this->assertStringContainsString('violating foreign keys', $e->getMessage());
        }
        $this->assertSame(2, (int) $this->conn()->fetchColumn('SELECT COUNT(*) FROM authors'));
        $this->assertSame(1, (int) $this->conn()->fetchColumn('PRAGMA foreign_keys'));
    }

    public function testRebuildInAUserTransactionOfAReferencedTableIsRefused(): void
    {
        $this->schema->createTable('chapters', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('book_id')->constrained()->cascadeOnDelete();
        });
        $this->expectException(OrmException::class);
        $this->expectExceptionMessage('chapters');
        $this->conn()->transaction(fn () => $this->schema->dropColumn('books', 'isbn'));
    }

    private function conn(): \Nesto\Orm\Connection
    {
        return $this->ds->getConnection();
    }

    private function exec(string $sql): void
    {
        $this->conn()->execute($sql);
    }

    /** @return list<list<mixed>> */
    private function rows(string $sql): array
    {
        return array_map(fn (array $r) => array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, array_values($r)), $this->conn()->fetchAll($sql));
    }

    /** @return list<string> explicit (CREATE INDEX) indexes, sorted */
    private function indexes(string $table): array
    {
        $names = array_column($this->conn()->fetchAll("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = :t AND sql IS NOT NULL", ['t' => $table]), 'name');
        sort($names);

        return $names;
    }

    private function sorted(array $a): array
    {
        ksort($a);

        return $a;
    }
}

final class SqliteRebuildMigration extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->dropColumn('books', 'isbn');
        $schema->table('books', fn (Blueprint $t) => $t->foreign('author_id')->on('authors'));
    }

    public function down(Schema $schema): void
    {
    }
}

final class SqliteBrokenReferenceMigration extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->raw('DELETE FROM authors WHERE id = 2');   // books.editor_id still points at it
    }

    public function down(Schema $schema): void
    {
    }
}
