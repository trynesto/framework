<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers;

use Nesto\Orm\Migration\Blueprint;
use Nesto\Orm\Migration\DiffPlan;
use Nesto\Orm\Schema\Comparison\Comparator;
use Nesto\Orm\Schema\Comparison\SchemaDiff;
use Nesto\Orm\Migration\MigrationGenerator;
use Nesto\Orm\Migration\Migrator;
use Nesto\Orm\QueryException;
use Nesto\Tests\Orm\Drivers\Fixtures\Schema\SdAuthor;
use Nesto\Tests\Orm\Drivers\Fixtures\Schema\SdBook;
use Nesto\Tests\Orm\Drivers\Fixtures\Schema\SdTag;
use Nesto\Tests\Orm\Drivers\Fixtures\Schema\V2;

/**
 * Schema diff scenarios run on every driver (used by DriverTestCase): an entity schema synchronized once
 * compares equal to the database (no false positives), changes are detected and applied in place without
 * losing data, generated migrations round-trip (up → entities, down → previous schema), and the
 * Blueprint alter API works.
 */
trait SchemaDiffScenarios
{
    /** @var list<class-string> */
    private static array $sdV1 = [SdAuthor::class, SdTag::class, SdBook::class];
    /** @var list<class-string> */
    private static array $sdV2 = [V2\SdAuthor::class, V2\SdTag::class, V2\SdPublisher::class, V2\SdBook::class];

    public function testSynchronizedEntitiesCompareEqualToTheDatabase(): void
    {
        $this->sdReset();
        try {
            $sync = $this->ds->synchronizer();
            $this->assertDiffEmpty($sync->diff(self::ENTITIES), 'driver fixtures');

            $sync->synchronize(self::RELATION_ENTITIES);
            $this->assertDiffEmpty($sync->diff(self::RELATION_ENTITIES), 'relation fixtures');

            $sync->synchronize(self::$sdV1);
            $this->assertDiffEmpty($sync->diff(self::$sdV1), 'schema fixtures v1');
            $this->assertSame([], $this->ddl(fn () => $sync->synchronize(self::$sdV1)), 'a second synchronize runs no DDL');

            $sync->synchronize(self::$sdV2);
            $this->assertDiffEmpty($sync->diff(self::$sdV2), 'schema fixtures v2');
            $this->assertSame([], $this->ddl(fn () => $sync->synchronize(self::$sdV2)));
        } finally {
            $this->sdReset();
            $this->ds->synchronizer()->drop(self::RELATION_ENTITIES);
        }
    }

    public function testSynchronizeAltersTablesInPlaceAndKeepsTheData(): void
    {
        $this->sdReset();
        try {
            $sync = $this->ds->synchronizer();
            $sync->synchronize(self::$sdV1);
            $this->sdSeed();

            $log = $sync->log(self::$sdV2);
            $this->assertNotSame([], $log);
            $this->assertContains('code', $this->ds->schema()->columns('sd_authors'), 'schema:log changes nothing');
            $this->assertFalse($this->ds->schema()->hasTable('sd_publishers'));

            $sync->synchronize(self::$sdV2);
            $this->assertDiffEmpty($sync->diff(self::$sdV2), 'after synchronizing v2');
            $this->sdAssertV2Data();

            // author_id is now ON DELETE RESTRICT
            try {
                $this->ds->getConnection()->execute('DELETE FROM ' . $this->q('sd_authors'));
                $this->fail('RESTRICT foreign key');
            } catch (QueryException) {
            }
            // email is no longer unique
            $this->sdInsert('sd_authors', ['name' => 'Bob', 'email' => 'ada@example.com', 'nickname' => 'b']);
        } finally {
            $this->sdReset();
        }
    }

    public function testGeneratedMigrationRoundTrips(): void
    {
        $this->sdReset();
        $dir = sys_get_temp_dir() . '/nesto-sd-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $sync = $this->ds->synchronizer();
            $sync->synchronize(self::$sdV1);
            $this->sdSeed();

            $generator = new MigrationGenerator($this->ds->schema());
            $class = 'SdMigration' . ucfirst(static::platform()) . bin2hex(random_bytes(3));
            $code = (string) $generator->generate($sync->tables(self::$sdV2), $class);
            $this->assertStringContainsString("\$schema->createTable('sd_publishers', function (Blueprint \$table): void {", $code);
            $this->assertStringContainsString("\$table->string('name', 120)->comment('Shown name')->change();", $code);
            $this->assertStringContainsString("\$schema->dropTable('sd_publishers');", $code);
            file_put_contents("$dir/2026_01_01_000000_sd.php", $code);

            $migrator = new Migrator($this->ds->getConnection(), [$dir], 'sd_migrations');
            $this->assertSame([$class], $migrator->run());
            $this->assertDiffEmpty($sync->diff(self::$sdV2), "after up()\n$code");
            $this->sdAssertV2Data();
            $this->assertNull($generator->generate($sync->tables(self::$sdV2), $class . 'Again'), 'nothing left to generate');

            $this->assertSame($class, $migrator->revert());
            $this->assertDiffEmpty($sync->diff(self::$sdV1), "after down()\n$code");
            $this->assertFalse($this->ds->schema()->hasTable('sd_publishers'));
            $row = $this->ds->getConnection()->fetchOne('SELECT * FROM ' . $this->q('sd_authors'));
            $this->assertSame('Ada', $row['name']);
            $this->assertSame(1, (int) $this->ds->getConnection()->fetchColumn('SELECT COUNT(*) FROM ' . $this->q('sd_book_tags')));
        } finally {
            array_map('unlink', glob("$dir/*.php") ?: []);
            rmdir($dir);
            $this->ds->schema()->dropTableIfExists('sd_migrations');
            $this->sdReset();
        }
    }

    public function testBlueprintAltersExistingTables(): void
    {
        $schema = $this->ds->schema();
        $conn = $this->ds->getConnection();
        $this->sdDropTables(['sd_alter_child', 'sd_alter', 'sd_pk']);
        try {
            $schema->createTable('sd_alter', function (Blueprint $t): void {
                $t->id();
                $t->string('a', 50);
                $t->integer('b')->default(1);
                $t->string('c')->unique();
                $t->string('d')->nullable();
            });
            $schema->createTable('sd_alter_child', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('parent_id')->constrained('sd_alter')->cascadeOnDelete();
            });
            $this->sdInsert('sd_alter', ['a' => 'x', 'c' => 'c1']);
            $this->sdInsert('sd_alter_child', ['parent_id' => 1]);

            $schema->table('sd_alter', function (Blueprint $t): void {
                $t->string('a', 100)->nullable()->comment('the a')->change();
                $t->bigInteger('b')->default(7)->change();
                $t->renameColumn('d', 'dd');
                $t->dropUnique(['c']);
                $t->index(['c']);
                $t->check('b >= 0', 'chk_sd_alter_b');
                $t->string('e')->default('E');
            });
            $table = $schema->introspect('sd_alter');
            $comparator = new Comparator($schema->grammar());
            $wanted = (new Blueprint('sd_alter'));
            $this->assertFalse($comparator->columnChanged($table->column('a'), $wanted->string('a', 100)->nullable()->comment('the a')), 'a changed in place');
            $this->assertFalse($comparator->columnChanged($table->column('b'), (new Blueprint('x'))->bigInteger('b')->default(7)));
            $this->assertNotNull($table->column('dd'));
            $this->assertNull($table->column('d'));
            $this->assertSame([['c']], array_map(fn ($i) => $i->columns, array_values(array_filter($table->indexes, fn ($i) => ! $i->implicit))));
            $this->assertFalse(array_values(array_filter($table->indexes, fn ($i) => ! $i->implicit))[0]->unique);
            $this->assertSame(['chk_sd_alter_b'], array_map(fn ($c) => $c->name, $table->checks));
            $row = $conn->fetchOne('SELECT * FROM ' . $this->q('sd_alter'));
            $this->assertSame(['x', 1, 'c1', 'E'], [$row['a'], (int) $row['b'], $row['c'], $row['e']], 'rows survive');
            try {
                $this->sdInsert('sd_alter', ['a' => 'y', 'b' => -1, 'c' => 'c2']);
                $this->fail('CHECK b >= 0');
            } catch (QueryException) {
            }
            $this->sdInsert('sd_alter', ['a' => 'y', 'c' => 'c1']); // no longer unique

            $schema->table('sd_alter', function (Blueprint $t): void {
                $t->dropIndex(['c']);
                $t->dropCheck('chk_sd_alter_b');
                $t->dropColumn('c', 'e');
            });
            $table = $schema->introspect('sd_alter');
            $this->assertSame(['id', 'a', 'b', 'dd'], array_keys($table->columns));
            $this->assertSame([], $table->checks);

            $schema->table('sd_alter_child', fn (Blueprint $t) => $t->dropForeign(['parent_id']));
            $this->assertSame([], $schema->introspect('sd_alter_child')->foreignKeys);
            $schema->table('sd_alter_child', fn (Blueprint $t) => $t->foreign('parent_id', 'fk_sd_child_parent')->references('id')->on('sd_alter')->onDelete('CASCADE'));
            $fks = $schema->introspect('sd_alter_child')->foreignKeys;
            $this->assertCount(1, $fks);
            $this->assertSame(['fk_sd_child_parent', 'CASCADE'], [$fks[0]->name, $fks[0]->onDelete]);

            // primary key change
            $schema->createTable('sd_pk', function (Blueprint $t): void {
                $t->string('code', 10);
                $t->string('locale', 5)->default('en');
                $t->primary('code');
            });
            $this->sdInsert('sd_pk', ['code' => 'a']);
            $schema->table('sd_pk', function (Blueprint $t): void {
                $t->dropPrimary();
                $t->primary(['code', 'locale']);
            });
            $this->assertSame(['code', 'locale'], $schema->introspect('sd_pk')->primaryKey);
            $this->sdInsert('sd_pk', ['code' => 'a', 'locale' => 'fr']);
            $this->assertSame(2, (int) $conn->fetchColumn('SELECT COUNT(*) FROM ' . $this->q('sd_pk')));

            // column collation (names differ per platform)
            $collation = match (static::platform()) {
                'mysql', 'mariadb' => 'utf8mb4_bin',
                'pgsql' => 'C',
                default => 'NOCASE',
            };
            $schema->table('sd_pk', fn (Blueprint $t) => $t->string('label', 20)->nullable()->collation($collation));
            $label = $schema->introspect('sd_pk')->column('label');
            $this->assertSame(strtolower($collation), strtolower((string) $label->collation));
            $this->assertFalse((new Comparator($schema->grammar()))->columnChanged($label, (new Blueprint('x'))->string('label', 20)->nullable()->collation($collation), $schema->introspect('sd_pk')));
        } finally {
            $this->sdDropTables(['sd_alter_child', 'sd_alter', 'sd_pk']);
        }
    }

    // ---- helpers ----------------------------------------------------------------------------------

    private function sdSeed(): void
    {
        $this->sdInsert('sd_authors', ['name' => 'Ada', 'email' => 'ada@example.com', 'code' => 'ADA', 'rating' => 5, 'views' => 10]);
        $this->sdInsert('sd_tags', ['label' => 'php']);
        $this->sdInsert('sd_books', ['title' => 'Notes', 'author_id' => 1]);
        $this->sdInsert('sd_book_tags', ['book_id' => 1, 'tag_id' => 1]);
    }

    private function sdAssertV2Data(): void
    {
        $conn = $this->ds->getConnection();
        $author = $conn->fetchOne('SELECT * FROM ' . $this->q('sd_authors'));
        $this->assertSame('Ada', $author['name']);
        $this->assertSame('', $author['nickname'], 'NOT NULL column added without default: existing rows get the neutral value');
        $this->assertSame([5, 10], [(int) $author['rating'], (int) $author['views']]);
        $this->assertArrayNotHasKey('code', $author);
        $this->assertArrayNotHasKey('avatar', $author);
        $book = $conn->fetchOne('SELECT * FROM ' . $this->q('sd_books'));
        $this->assertSame(['Notes', 1], [$book['title'], (int) $book['author_id']]);
        $this->assertNull($book['publisher_id']);
        $this->assertSame(1, (int) $conn->fetchColumn('SELECT COUNT(*) FROM ' . $this->q('sd_book_tags')));
        $this->assertTrue($this->ds->schema()->hasTable('sd_publishers'));
    }

    /** @param array<string, mixed> $row */
    private function sdInsert(string $table, array $row): void
    {
        $columns = implode(', ', array_map(fn ($c) => $this->q($c), array_keys($row)));
        $params = implode(', ', array_map(fn ($c) => ':' . $c, array_keys($row)));
        $this->ds->getConnection()->execute('INSERT INTO ' . $this->q($table) . " ($columns) VALUES ($params)", $row);
    }

    private function sdReset(): void
    {
        $sync = $this->ds->synchronizer();
        $sync->drop(self::$sdV2);
        $sync->drop(self::$sdV1);
    }

    /** @param list<string> $tables */
    private function sdDropTables(array $tables): void
    {
        $conn = $this->ds->getConnection();
        if ($conn->driver() === 'mysql') {
            $conn->execute('SET FOREIGN_KEY_CHECKS = 0');
        }
        foreach ($tables as $table) {
            $this->ds->schema()->dropTableIfExists($table);
        }
        if ($conn->driver() === 'mysql') {
            $conn->execute('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /** DDL statements run by $fn. @return list<string> */
    private function ddl(\Closure $fn): array
    {
        $conn = $this->ds->getConnection();
        $conn->enableQueryLog()->flushQueryLog();
        try {
            $fn();
        } finally {
            $log = $conn->getQueryLog();
            $conn->disableQueryLog();
        }

        return array_values(array_filter(array_column($log, 'sql'), fn (string $sql) => preg_match('/^\s*(CREATE|ALTER|DROP|COMMENT)\b/i', $sql) === 1));
    }

    private function assertDiffEmpty(SchemaDiff $diff, string $context): void
    {
        if ($diff->isEmpty()) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail("$context: expected no schema difference on " . static::platform() . ", got:\n" . implode("\n", (new DiffPlan($diff))->toSql($this->ds->schema())));
    }
}
