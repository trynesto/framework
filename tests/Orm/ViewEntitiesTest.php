<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\Attributes\ViewColumn;
use Nesto\Orm\Attributes\ViewEntity;
use Nesto\Orm\DataSource;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\Migration\Migration;
use Nesto\Orm\Migration\Schema;
use Nesto\Orm\OrmException;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaAuthor;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaAuthorStats;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaAuthorStatsV2;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaBusyAuthor;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaNote;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaNoteTotals;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaPopularNote;
use PHPUnit\Framework\TestCase;

/** TypeORM's view entities (#[ViewEntity] / #[ViewColumn]) on SQLite; see the driver scenarios for the others. */
final class ViewEntitiesTest extends TestCase
{
    private const TABLES = [DaAuthor::class, DaNote::class];
    private const VIEWS = [DaAuthorStats::class, DaBusyAuthor::class, DaPopularNote::class, DaNoteTotals::class];

    protected function setUp(): void
    {
        MetadataRegistry::clear();
    }

    private function dataSource(array $entities, string $file = ':memory:'): DataSource
    {
        return new DataSource(['type' => 'sqlite', 'database' => $file, 'entities' => $entities]);
    }

    private static function seed(DataSource $ds): void
    {
        $authors = $ds->getRepository(DaAuthor::class);
        $notes = $ds->getRepository(DaNote::class);
        $ada = $authors->save($authors->create(['name' => 'Ada']));
        $bob = $authors->save($authors->create(['name' => 'Bob']));
        $authors->save($authors->create(['name' => 'Cyd']));
        $notes->save([
            $notes->create(['title' => 'a1', 'views' => 10, 'author' => $ada]),
            $notes->create(['title' => 'a2', 'views' => 0, 'author' => $ada]),
            $notes->create(['title' => 'b1', 'views' => 30, 'author' => $bob]),
        ]);
    }

    public function testMetadata(): void
    {
        $meta = MetadataRegistry::get(DaAuthorStats::class);
        $this->assertTrue(MetadataRegistry::has(DaAuthorStats::class));
        $this->assertTrue($meta->isView());
        $this->assertSame('da_author_stats', $meta->table);
        $this->assertSame(['id', 'name', 'note_count', 'total_views'], array_keys($meta->columns));
        $this->assertSame('id', $meta->primary->name);
        $this->assertSame(['da_author_stats'], MetadataRegistry::get(DaBusyAuthor::class)->view->dependsOn);
        $this->assertSame('title', MetadataRegistry::get(DaPopularNote::class)->primary->name, 'no id column: the first one');
        $this->assertTrue(MetadataRegistry::get(DaNoteTotals::class)->view->materialized);
    }

    public function testSynchronizeCreatesViewsAndTheyAreReadLikeEntities(): void
    {
        $ds = $this->dataSource([...self::TABLES, ...self::VIEWS]);
        $ds->synchronize();
        self::seed($ds);
        $schema = $ds->schema();
        foreach (['da_author_stats', 'da_busy_authors', 'da_popular_notes', 'da_note_totals'] as $view) {
            $this->assertTrue($schema->hasView($view), $view);
        }
        $this->assertFalse($schema->hasTable('da_author_stats'));
        $recorded = $ds->manager->query('SELECT type, name FROM typeorm_metadata ORDER BY name');
        $this->assertSame([
            ['type' => 'VIEW', 'name' => 'da_author_stats'],
            ['type' => 'VIEW', 'name' => 'da_busy_authors'],
            ['type' => 'VIEW', 'name' => 'da_note_totals'],
            ['type' => 'VIEW', 'name' => 'da_popular_notes'],
        ], $recorded);
        $this->assertSame([], $ds->logSchema(), 'sync then diff is empty');
        $this->assertNull($ds->generateMigration('Nothing'));

        $stats = $ds->getRepository(DaAuthorStats::class);
        $rows = $stats->find(['order' => ['name' => 'ASC']]);
        $this->assertSame([['Ada', 2, 10], ['Bob', 1, 30], ['Cyd', 0, 0]], array_map(fn (DaAuthorStats $s) => [$s->name, $s->notes, $s->totalViews], $rows));
        $this->assertSame('Bob', $stats->findOneBy(['totalViews' => 30])?->name);
        $this->assertSame(2, $stats->count(['where' => ['notes' => ['>', 0]]]));
        $this->assertSame(['Ada'], array_map(fn (DaBusyAuthor $a) => $a->name, $ds->getRepository(DaBusyAuthor::class)->find()));
        $popular = $ds->createQueryBuilder(DaPopularNote::class, 'p')->orderBy('p.views', 'DESC')->getMany();
        $this->assertSame(['b1', 'a1'], array_map(fn (DaPopularNote $p) => $p->title, $popular));
        $totals = $ds->getRepository(DaNoteTotals::class)->find();
        $this->assertCount(1, $totals);
        $this->assertSame([3, 40], [$totals[0]->notes, $totals[0]->views]);
        $this->assertSame(['b1'], array_map(fn (DaPopularNote $p) => $p->title, iterator_to_array($ds->getRepository(DaPopularNote::class)->findIterable(['where' => ['views' => 30]]), false)));
    }

    public function testViewEntitiesAreReadOnly(): void
    {
        $ds = $this->dataSource([...self::TABLES, ...self::VIEWS]);
        $ds->synchronize();
        self::seed($ds);
        $stats = $ds->getRepository(DaAuthorStats::class);
        $row = $stats->findOneByOrFail(['name' => 'Ada']);
        foreach ([
            fn () => $stats->save($row),
            fn () => $ds->manager->save([$row]),
            fn () => $stats->remove($row),
            fn () => $stats->insert(['id' => 9, 'name' => 'x']),
            fn () => $stats->update(1, ['name' => 'x']),
            fn () => $stats->delete(1),
            fn () => $stats->increment(1, 'notes'),
            fn () => $stats->upsert([['id' => 1, 'name' => 'x']], ['id']),
            fn () => $ds->createQueryBuilder()->delete()->from(DaAuthorStats::class)->execute(),
        ] as $i => $write) {
            try {
                $write();
                $this->fail("write #$i on a view entity must throw");
            } catch (OrmException $e) {
                $this->assertStringContainsString('is a view entity', $e->getMessage());
            }
        }
    }

    public function testChangedExpressionsRecreateTheViewAndItsDependents(): void
    {
        $file = sys_get_temp_dir() . '/nesto-views-' . bin2hex(random_bytes(4)) . '.sqlite';
        try {
            $v1 = $this->dataSource([...self::TABLES, DaAuthorStats::class, DaBusyAuthor::class], $file);
            $v1->synchronize();
            self::seed($v1);
            $v1->destroy();

            $v2 = $this->dataSource([...self::TABLES, DaAuthorStatsV2::class, DaBusyAuthor::class], $file);
            $log = $v2->logSchema();
            $this->assertSame([
                'DROP VIEW IF EXISTS "da_busy_authors"',
                'DELETE FROM "typeorm_metadata" WHERE "type" IN (\'VIEW\', \'MATERIALIZED_VIEW\') AND "name" = \'da_busy_authors\'',
                'DROP VIEW IF EXISTS "da_author_stats"',
                'DELETE FROM "typeorm_metadata" WHERE "type" IN (\'VIEW\', \'MATERIALIZED_VIEW\') AND "name" = \'da_author_stats\'',
            ], array_slice($log, 0, 4), 'the dependent view goes first');
            $this->assertStringStartsWith('CREATE VIEW "da_author_stats" AS SELECT', $log[4]);
            $this->assertStringContainsString('"n"."views" > 0', $log[4], 'the QueryBuilder parameter is inlined');
            $this->assertStringStartsWith('CREATE VIEW "da_busy_authors" AS SELECT id, name FROM da_author_stats', $log[6]);

            $migration = (string) $v2->generateMigration('ChangeStats');
            $this->assertStringContainsString("\$schema->dropView('da_busy_authors');", $migration);
            $this->assertStringContainsString("\$schema->createView('da_author_stats', 'SELECT", $migration);

            $v2->synchronize();
            $this->assertSame([], $v2->logSchema());
            $this->assertSame([['Ada', 1], ['Bob', 1], ['Cyd', 0]], array_map(fn (DaAuthorStatsV2 $s) => [$s->name, $s->notes], $v2->getRepository(DaAuthorStatsV2::class)->find(['order' => ['name' => 'ASC']])));
            $this->assertSame([], $v2->getRepository(DaBusyAuthor::class)->find());

            // a view that exists without a recorded definition (created by hand) is replaced
            $v2->manager->query('DELETE FROM typeorm_metadata WHERE name = :n', ['n' => 'da_busy_authors']);
            $this->assertCount(4, $v2->logSchema());

            // dropping the entities drops the views too
            $v2->synchronizer()->drop([DaBusyAuthor::class, DaAuthorStatsV2::class]);
            $this->assertFalse($v2->schema()->hasView('da_author_stats'));
            $this->assertSame(0, (int) $v2->manager->query('SELECT COUNT(*) AS n FROM typeorm_metadata')[0]['n']);
            $v2->destroy();
        } finally {
            @unlink($file);
        }
    }

    public function testGeneratedMigrationCreatesAndDropsViews(): void
    {
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [...self::TABLES, ...self::VIEWS], 'metadataTableName' => 'orm_metadata']);
        $code = (string) $ds->generateMigration('CreateViews1700000000000');
        $this->assertStringContainsString("\$schema->createView('da_popular_notes', 'SELECT title, views FROM da_notes WHERE views >= 10');", $code);
        $this->assertLessThan(strpos($code, "createView('da_busy_authors'"), strpos($code, "createView('da_author_stats'"), 'dependencies first');
        $this->assertStringContainsString("\$schema->dropView('da_busy_authors');", $code);
        eval(substr($code, 5));
        $migration = new \CreateViews1700000000000;
        $ds2 = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [...self::TABLES, ...self::VIEWS], 'metadataTableName' => 'orm_metadata', 'migrations' => [$migration]]);
        $ds2->runMigrations();
        $this->assertTrue($ds2->schema()->hasView('da_busy_authors'));
        $this->assertSame([], $ds2->logSchema(), 'the migration recorded the view definitions');
        $ds2->revertLastMigration();
        $this->assertFalse($ds2->schema()->hasView('da_busy_authors'));
        $this->assertFalse($ds2->schema()->hasTable('da_notes'));
        $this->assertSame(0, (int) $ds2->manager->query('SELECT COUNT(*) AS n FROM orm_metadata')[0]['n']);
    }

    public function testSchemaApiAndErrors(): void
    {
        $ds = $this->dataSource(self::TABLES);
        $ds->synchronize();
        $schema = $ds->schema();
        $sql = $schema->pretend(function (Schema $s): void {
            $s->createView('v1', 'SELECT 1 AS one');
            $s->createView('v2', 'SELECT 2 AS two', materialized: true);
        });
        $this->assertSame('CREATE VIEW "v1" AS SELECT 1 AS one', $sql[0]);
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS "typeorm_metadata"', $sql[1]);
        $this->assertSame('CREATE VIEW "v2" AS SELECT 2 AS two', $sql[3], 'materialized only on PostgreSQL');
        $this->assertCount(5, $sql, 'the metadata table is created once');
        $this->assertFalse($schema->hasView('v1'));

        $this->expectException(OrmException::class);
        MetadataRegistry::get(NoColumnsView::class);
    }
}

#[ViewEntity(expression: 'SELECT 1')]
final class NoColumnsView
{
    public int $id;
}
