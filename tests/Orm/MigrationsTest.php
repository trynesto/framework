<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\DataSource;
use Nesto\Orm\Migration\Blueprint;
use Nesto\Orm\Migration\Grammar;
use Nesto\Orm\Migration\Migrator;
use Nesto\Orm\Migration\Schema;
use Nesto\Tests\Orm\Fixtures\CreateAuthorsMigration;
use Nesto\Tests\Orm\Fixtures\CreateBooksMigration;
use PHPUnit\Framework\TestCase;

final class MigrationsTest extends TestCase
{
    private DataSource $ds;

    protected function setUp(): void
    {
        $this->ds = new DataSource([
            'type' => 'sqlite',
            'database' => ':memory:',
            'migrations' => [CreateAuthorsMigration::class, new CreateBooksMigration],
        ]);
    }

    public function testRunRevertAndStatus(): void
    {
        $schema = $this->ds->schema();
        $status = $this->ds->showMigrations();
        $this->assertSame([false, false], array_column($status, 'ran'));

        $this->assertSame([CreateAuthorsMigration::class, CreateBooksMigration::class], $this->ds->runMigrations());
        $this->assertTrue($schema->hasTable('migrations'));
        $this->assertTrue($schema->hasTable('authors'));
        $this->assertTrue($schema->hasTable('books'));
        $this->assertContains('tagline', $schema->columns('books'));
        $this->assertNotContains('subtitle', $schema->columns('books'));
        $this->assertSame([1, 1], array_column($this->ds->showMigrations(), 'batch'));
        $this->assertSame([], $this->ds->runMigrations()); // nothing pending

        $conn = $this->ds->getConnection();
        $conn->execute('INSERT INTO authors (name, email) VALUES (:n, :e)', ['n' => 'A', 'e' => 'a@x']);
        $row = $conn->fetchOne('SELECT * FROM authors');
        $this->assertSame(1, (int) $row['active']);
        $this->assertSame('writer', $row['role']);

        $this->assertSame(CreateBooksMigration::class, $this->ds->revertLastMigration());
        $this->assertFalse($schema->hasTable('books'));
        $this->assertTrue($schema->hasTable('authors'));
        $this->assertSame([true, false], array_column($this->ds->showMigrations(), 'ran'));
        $this->assertSame(CreateAuthorsMigration::class, $this->ds->revertLastMigration());
        $this->assertNull($this->ds->revertLastMigration());
        $this->assertFalse($schema->hasTable('authors'));
    }

    public function testGrammarPerDriver(): void
    {
        $bp = new Blueprint('things');
        $bp->id();
        $bp->string('name', 50)->unique();
        $bp->boolean('on')->default(false);
        $bp->json('data')->nullable();
        $bp->foreignId('owner_id')->constrained()->nullOnDelete();
        $bp->index(['name', 'on'], 'idx_custom');

        $sqlite = (new Grammar($this->ds->getConnection()))->compileCreate($bp);
        $this->assertSame(
            'CREATE TABLE "things" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "name" VARCHAR(50) NOT NULL UNIQUE, "on" INTEGER NOT NULL DEFAULT 0, "data" TEXT, "owner_id" INTEGER NOT NULL, FOREIGN KEY ("owner_id") REFERENCES "owners" ("id") ON DELETE SET NULL)',
            $sqlite[0],
        );
        $this->assertSame('CREATE INDEX "idx_custom" ON "things" ("name", "on")', $sqlite[1]);

        $mysql = (new Grammar(new \Nesto\Orm\Connection($this->ds->getConnection()->pdo(), 'mysql')))->compileCreate($bp)[0];
        $this->assertStringContainsString('`id` BIGINT AUTO_INCREMENT PRIMARY KEY', $mysql);
        $this->assertStringContainsString('`on` TINYINT(1) NOT NULL DEFAULT 0', $mysql);
        $this->assertStringContainsString('`data` JSON', $mysql);
        $this->assertStringContainsString('ENGINE=InnoDB', $mysql);

        $pgsql = (new Grammar(new \Nesto\Orm\Connection($this->ds->getConnection()->pdo(), 'pgsql')))->compileCreate($bp)[0];
        $this->assertStringContainsString('"id" BIGSERIAL PRIMARY KEY', $pgsql);
        $this->assertStringContainsString('"on" BOOLEAN NOT NULL DEFAULT FALSE', $pgsql);
        $this->assertStringContainsString('"data" JSONB', $pgsql);
    }

    public function testMigrationsLoadedFromDirectoryEvenWhenAlreadyIncluded(): void
    {
        $dir = sys_get_temp_dir() . '/nesto-orm-mig-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/2024_01_01_000000_create_widgets.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace Nesto\Tests\Orm\Generated;
        use Nesto\Orm\Migration\Blueprint;
        use Nesto\Orm\Migration\Migration;
        use Nesto\Orm\Migration\Schema;
        final class CreateWidgets extends Migration
        {
            public function up(Schema $schema): void { $schema->createTable('widgets', fn (Blueprint $t) => $t->id()); }
            public function down(Schema $schema): void { $schema->dropTable('widgets'); }
        }
        PHP);
        try {
            $first = new Migrator($this->ds->getConnection(), [$dir]);
            $this->assertSame(['Nesto\Tests\Orm\Generated\CreateWidgets'], array_map(fn ($m) => $m->name(), $first->migrations()));
            $this->assertSame(['Nesto\Tests\Orm\Generated\CreateWidgets'], $first->run());
            // a second Migrator in the same process (file already included) still discovers the class
            $second = (new Migrator($this->ds->getConnection()))->add($dir);
            $this->assertCount(1, $second->migrations());
            $this->assertSame([true], array_column($second->status(), 'ran'));
            $this->assertSame('Nesto\Tests\Orm\Generated\CreateWidgets', $second->revert());
            $this->assertFalse($this->ds->schema()->hasTable('widgets'));
        } finally {
            array_map('unlink', glob($dir . '/*.php') ?: []);
            rmdir($dir);
        }
    }

    public function testSchemaHelpers(): void
    {
        $schema = new Schema($this->ds->getConnection());
        $schema->createTable('t', fn (Blueprint $t) => $t->id() && $t->string('a'));
        $schema->addColumn('t', fn (Blueprint $t) => $t->integer('b')->default(2));
        $schema->renameColumn('t', 'b', 'c');
        $this->assertSame(['id', 'a', 'c'], $schema->columns('t'));
        $schema->dropColumn('t', 'c');
        $this->assertFalse($schema->hasColumn('t', 'c'));
        $schema->renameTable('t', 't2');
        $this->assertTrue($schema->hasTable('t2'));
        $schema->raw('INSERT INTO t2 (a) VALUES (:a)', ['a' => 'x']);
        $this->assertSame(1, (int) $this->ds->getConnection()->fetchColumn('SELECT COUNT(*) FROM t2'));
        $schema->dropTableIfExists('t2');
        $schema->dropTableIfExists('t2');
        $this->assertFalse($schema->hasTable('t2'));
    }
}
