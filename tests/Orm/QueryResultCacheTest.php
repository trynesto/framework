<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Cache\Stores\ArrayStore;
use Nesto\Orm\Cache\DbQueryResultCache;
use Nesto\Orm\Cache\Psr16QueryResultCache;
use Nesto\Orm\Cache\QueryResultCache;
use Nesto\Orm\DataSource;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\OrmException;
use Nesto\Orm\QueryRunner;
use Nesto\Orm\Repository;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaAuthor;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaNote;
use PHPUnit\Framework\TestCase;

/** TypeORM's query result cache: the `cache` DataSource option, the `cache` find option and QueryBuilder::cache(). */
final class QueryResultCacheTest extends TestCase
{
    protected function setUp(): void
    {
        MetadataRegistry::clear();
    }

    private function dataSource(mixed $cache = true): DataSource
    {
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [DaAuthor::class, DaNote::class], 'synchronize' => true, 'cache' => $cache]);
        $ds->initialize();
        $notes = $ds->getRepository(DaNote::class);
        $notes->save([$notes->create(['title' => 'one', 'views' => 1]), $notes->create(['title' => 'two', 'views' => 2])]);

        return $ds;
    }

    private static function titles(array $notes): array
    {
        return array_map(fn (DaNote $n) => $n->title, $notes);
    }

    /** Change the rows behind the cache's back. */
    private static function rename(DataSource $ds, string $from, string $to): void
    {
        $ds->manager->query('UPDATE da_notes SET title = :to WHERE title = :from', ['to' => $to, 'from' => $from]);
    }

    private static function selects(DataSource $ds): int
    {
        return count(array_filter($ds->getConnection()->getQueryLog(), fn (array $q) => str_contains($q['sql'], 'FROM "da_notes"')));
    }

    public function testDatabaseCacheTableIsPartOfTheSchema(): void
    {
        $ds = $this->dataSource();
        $this->assertInstanceOf(DbQueryResultCache::class, $ds->queryResultCache);
        $this->assertTrue($ds->schema()->hasTable('query-result-cache'));
        $this->assertSame(['id', 'identifier', 'time', 'duration', 'query', 'result'], $ds->schema()->columns('query-result-cache'));
        $this->assertSame([], $ds->logSchema(), 'synchronize then diff is empty');
        $this->assertNull($ds->generateMigration('Nothing'));

        $fresh = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [DaNote::class, DaAuthor::class], 'cache' => ['tableName' => 'orm_cache']]);
        $migration = (string) $fresh->generateMigration('AddCache');
        $this->assertStringContainsString("\$schema->createTable('orm_cache'", $migration);
        $this->assertStringContainsString("\$schema->dropTable('orm_cache');", $migration);
        $fresh->queryResultCache->synchronize();
        $this->assertTrue($fresh->schema()->hasTable('orm_cache'));
        $fresh->dropDatabase();
        $this->assertFalse($fresh->schema()->hasTable('orm_cache'));
    }

    public function testFindOptionCachesTheResult(): void
    {
        $ds = $this->dataSource();
        $notes = $ds->getRepository(DaNote::class);
        $ds->getConnection()->enableQueryLog();

        $this->assertSame(['one', 'two'], self::titles($notes->find(['cache' => true])));
        self::rename($ds, 'one', 'uno');
        $this->assertSame(['one', 'two'], self::titles($notes->find(['cache' => true])), 'served from the cache');
        $this->assertSame(['uno', 'two'], self::titles($notes->find()), 'no cache option, no cache');
       $this->assertSame(2, self::selects($ds), "the cached find ran one SELECT");
        $this->assertSame(1, (int) $ds->manager->query('SELECT COUNT(*) AS n FROM "query-result-cache"')[0]['n']);

        // expiry: entries older than their duration are refreshed
        $this->assertSame(['uno', 'two'], self::titles($notes->find(['cache' => 1, 'where' => ['views' => [1, 2]]])));
        self::rename($ds, 'uno', 'eins');
        usleep(5000);
        $this->assertSame(['eins', 'two'], self::titles($notes->find(['cache' => 1, 'where' => ['views' => [1, 2]]])));
        $this->assertSame(2, (int) $ds->manager->query('SELECT COUNT(*) AS n FROM "query-result-cache"')[0]['n'], 'the expired entry was updated, not duplicated');

        // findOne / count / findAndCount with an id
        $this->assertSame('two', $notes->findOne(['where' => ['views' => 2], 'cache' => ['id' => 'note-two', 'milliseconds' => 60_000]])?->title);
        $this->assertSame(2, $notes->count(['cache' => ['id' => 'all-notes', 'milliseconds' => 60_000]]));
        $notes->save($notes->create(['title' => 'three', 'views' => 3]));
        self::rename($ds, 'two', 'zwei');
        $this->assertSame('two', $notes->findOne(['where' => ['views' => 2], 'cache' => ['id' => 'note-two', 'milliseconds' => 60_000]])?->title);
        $this->assertSame(2, $notes->count(['cache' => ['id' => 'all-notes', 'milliseconds' => 60_000]]));
        $ids = array_column($ds->manager->query('SELECT identifier FROM "query-result-cache" WHERE identifier IS NOT NULL ORDER BY identifier'), 'identifier');
        $this->assertSame(['all-notes-count', 'note-two'], $ids);

        $ds->queryResultCache->remove(['note-two', 'all-notes-count']);
        $this->assertSame('zwei', $notes->findOne(['where' => ['views' => 2], 'cache' => ['id' => 'note-two', 'milliseconds' => 60_000]])?->title);
        $this->assertSame(3, $notes->count(['cache' => ['id' => 'all-notes', 'milliseconds' => 60_000]]));
        $ds->queryResultCache->clear();
        $this->assertSame(0, (int) $ds->manager->query('SELECT COUNT(*) AS n FROM "query-result-cache"')[0]['n']);
    }

    public function testQueryBuilderCache(): void
    {
        $ds = $this->dataSource(['duration' => 60_000]);
        $qb = fn () => $ds->createQueryBuilder(DaNote::class, 'n')->orderBy('n.id');

        $this->assertSame(['one', 'two'], self::titles($qb()->cache(true)->getMany()));
        $this->assertSame([['title' => 'one'], ['title' => 'two']], $qb()->select(['n.title AS title'])->cache('raw-titles')->getRawMany());
        $this->assertSame(['t' => 'one'], $qb()->select(['n.title AS t'])->cache(30_000)->getRawOne());
        $this->assertSame(2, $qb()->cache('counted', 30_000)->getCount());
        self::rename($ds, 'one', 'uno');
        $this->assertSame(['one', 'two'], self::titles($qb()->cache(true)->getMany()));
        $this->assertSame([['title' => 'one'], ['title' => 'two']], $qb()->select(['n.title AS title'])->cache('raw-titles')->getRawMany());
        $this->assertSame(['t' => 'one'], $qb()->select(['n.title AS t'])->cache(30_000)->getRawOne());
        $this->assertSame(['uno', 'two'], self::titles($qb()->getMany()));
        $this->assertSame(['uno', 'two'], self::titles($qb()->cache(false)->getMany()));
        $ds->manager->query('DELETE FROM da_notes WHERE title = :t', ['t' => 'uno']);
        $this->assertSame(2, $qb()->cache('counted', 30_000)->getCount());

        // paginated to-many joins: the id pre-query is cached under "<id>-pagination"
        $authors = $ds->getRepository(DaAuthor::class);
        $ada = $authors->save($authors->create(['name' => 'Ada']));
        $ds->getRepository(DaNote::class)->save($ds->getRepository(DaNote::class)->create(['title' => 'by ada', 'author' => $ada]));
        $paged = fn () => $ds->createQueryBuilder(DaAuthor::class, 'a')->leftJoinAndSelect('a.notes', 'n')->take(5)->cache('authors-page');
        $this->assertSame(['by ada'], self::titles($paged()->getMany()[0]->notes));
        $ids = array_column($ds->manager->query('SELECT identifier FROM "query-result-cache" WHERE identifier LIKE :p ORDER BY identifier', ['p' => 'authors-page%']), 'identifier');
        $this->assertSame(['authors-page', 'authors-page-pagination'], $ids);
        self::rename($ds, 'by ada', 'changed');
        $this->assertSame(['by ada'], self::titles($paged()->getMany()[0]->notes));
    }

    public function testAlwaysEnabled(): void
    {
        $ds = $this->dataSource(['alwaysEnabled' => true, 'duration' => 60_000]);
        $notes = $ds->getRepository(DaNote::class);
        $this->assertSame(['one', 'two'], self::titles($notes->find()));
        self::rename($ds, 'one', 'uno');
        $this->assertSame(['one', 'two'], self::titles($notes->find()));
        $this->assertSame(['uno', 'two'], self::titles($notes->find(['cache' => false])));
        $this->assertSame(['uno', 'two'], self::titles($ds->createQueryBuilder(DaNote::class, 'n')->cache(false)->getMany()));
    }

    public function testPsr16CacheAndProviders(): void
    {
        $store = new ArrayStore;
        $ds = $this->dataSource(['type' => 'redis', 'options' => $store, 'duration' => 60_000]);
        $this->assertInstanceOf(Psr16QueryResultCache::class, $ds->queryResultCache);
        $this->assertFalse($ds->schema()->hasTable('query-result-cache'));
        $notes = $ds->getRepository(DaNote::class);
        $this->assertSame(['one', 'two'], self::titles($notes->find(['cache' => ['id' => 'notes']])));
        $this->assertSame(['one', 'two'], self::titles($notes->find(['cache' => true])));
        self::rename($ds, 'one', 'uno');
        $this->assertSame(['one', 'two'], self::titles($notes->find(['cache' => ['id' => 'notes']])));
        $this->assertSame(['one', 'two'], self::titles($notes->find(['cache' => true])));
        $ds->queryResultCache->remove(['notes']);
        $this->assertSame(['uno', 'two'], self::titles($notes->find(['cache' => ['id' => 'notes']])));
        $this->assertSame(['one', 'two'], self::titles($notes->find(['cache' => true])));
        $ds->queryResultCache->clear();
        $this->assertSame(['uno', 'two'], self::titles($notes->find(['cache' => true])));

        $psr16 = $this->dataSource(['type' => 'psr16', 'options' => fn () => new ArrayStore]);
        $this->assertInstanceOf(Psr16QueryResultCache::class, $psr16->queryResultCache);

        $custom = new class implements QueryResultCache {
            public array $calls = [];

            public function connect(): void {}

            public function disconnect(): void {}

            public function synchronize(?QueryRunner $queryRunner = null): void {}

            public function getFromCache(array $options, ?QueryRunner $queryRunner = null): ?array
            {
                $this->calls[] = 'get';
                throw new \RuntimeException('cache down');
            }

            public function storeInCache(array $options, ?array $savedCache, ?QueryRunner $queryRunner = null): void
            {
                $this->calls[] = 'store';
            }

            public function isExpired(array $savedCache): bool
            {
                return true;
            }

            public function clear(?QueryRunner $queryRunner = null): void {}

            public function remove(array $identifiers, ?QueryRunner $queryRunner = null): void {}
        };
        $ds = $this->dataSource(['provider' => fn (DataSource $d) => $custom, 'ignoreErrors' => true]);
        $this->assertSame($custom, $ds->queryResultCache);
        $this->assertSame(['one', 'two'], self::titles($ds->getRepository(DaNote::class)->find(['cache' => true])), 'ignoreErrors: the database answers');
        $this->assertSame(['get'], $custom->calls, 'nothing is stored after a cache error');

        $strict = $this->dataSource(['provider' => fn () => $custom]);
        $this->expectExceptionMessage('cache down');
        $strict->getRepository(DaNote::class)->find(['cache' => true]);
    }

    public function testOrmModuleUsesTheApplicationsPsr16Store(): void
    {
        $app = \Nesto\Core\NestoFactory::create(\Nesto\Tests\Orm\Fixtures\DataAccess\CachedAppModule::class);
        $app->init();
        $ds = $app->get(DataSource::class);
        $store = $app->get(\Psr\SimpleCache\CacheInterface::class);
        $this->assertInstanceOf(Psr16QueryResultCache::class, $ds->queryResultCache);
        $this->assertSame($store, $ds->queryResultCache->store());
        $this->assertInstanceOf(\Nesto\Orm\Logging\SimpleConsoleLogger::class, $ds->logger);
        $this->assertFalse($ds->logger->isLogEnabledFor('query'));
        $notes = $ds->getRepository(DaNote::class);
        $notes->save($notes->create(['title' => 'cached']));
        $this->assertSame(['cached'], self::titles($notes->find(['cache' => ['id' => 'module-notes']])));
        self::rename($ds, 'cached', 'changed');
        $this->assertSame(['cached'], self::titles($notes->find(['cache' => ['id' => 'module-notes']])));
    }

    public function testInvalidCacheOptions(): void
    {
        foreach ([['type' => 'memcached'], ['type' => 'redis'], ['provider' => fn () => new \stdClass]] as $option) {
            try {
                new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'cache' => $option]);
                $this->fail('expected an exception');
            } catch (OrmException) {
                $this->addToAssertionCount(1);
            }
        }
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [DaNote::class, DaAuthor::class], 'synchronize' => true]);
        $this->assertNull($ds->queryResultCache);
        $this->assertSame([], $ds->getRepository(DaNote::class)->find(['cache' => true]), 'without a cache the option does nothing');
    }
}
