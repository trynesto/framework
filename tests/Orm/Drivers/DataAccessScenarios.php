<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers;

use Nesto\Orm\Connection;
use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\Schema\Introspection\Introspector;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaAuthor;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaAuthorStats;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaAuthorStatsV2;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaBusyAuthor;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaNote;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaNoteTotals;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaNoteV2;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaPopularNote;

/**
 * Replication, query result cache, streaming and view entities on every driver, with the DataAccess fixtures
 * (tables da_authors / da_notes, views da_*, "query-result-cache", typeorm_metadata — dropped before and after
 * each scenario). Replicas are simulated by a second connection to the same database. Used by DriverTestCase.
 */
trait DataAccessScenarios
{
    private const DA_TABLES = [DaAuthor::class, DaNote::class];
    private const DA_VIEWS = [DaAuthorStats::class, DaBusyAuthor::class, DaPopularNote::class, DaNoteTotals::class];
    private ?string $daFile = null;

    /** Connection options of the test database; SQLite uses a file so that several connections share it. */
    private function daNodeOptions(): array
    {
        if (static::platform() !== 'sqlite') {
            return ['url' => (string) static::options()['url']];
        }
        $this->daFile ??= sys_get_temp_dir() . '/nesto-da-' . bin2hex(random_bytes(4)) . '.sqlite';

        return ['type' => 'sqlite', 'database' => $this->daFile];
    }

    private function daDataSource(array $entities, array $options = []): DataSource
    {
        $ds = new DataSource($options + $this->daNodeOptions() + ['entities' => $entities]);
        $ds->initialize();

        return $ds;
    }

    #[\PHPUnit\Framework\Attributes\Before]
    protected function dropDataAccessSchemaBefore(): void
    {
        if (static::options() !== null) {
            $this->dropDataAccessSchema();
        }
    }

    #[\PHPUnit\Framework\Attributes\After]
    protected function dropDataAccessSchemaAfter(): void
    {
        if (static::options() === null) {
            return;
        }
        $this->dropDataAccessSchema();
        if ($this->daFile !== null) {
            @unlink($this->daFile);
            $this->daFile = null;
        }
    }

    private function dropDataAccessSchema(): void
    {
        $ds = new DataSource($this->daNodeOptions());
        $schema = $ds->schema();
        foreach (array_reverse(Introspector::for($ds->getConnection())->viewNames(), true) as $name => $materialized) {
            if (str_starts_with($name, 'da_')) {
                $schema->dropView($name, $materialized);
            }
        }
        $conn = $ds->getConnection();
        foreach (['da_notes', 'da_authors', 'query-result-cache', 'typeorm_metadata'] as $table) {
            $schema->dropTableIfExists($table);
        }
        $ds->destroy();
        unset($conn);
    }

    private static function daSeed(DataSource $ds, int $notes = 0): void
    {
        $authors = $ds->getRepository(DaAuthor::class);
        $repo = $ds->getRepository(DaNote::class);
        $ada = $authors->save($authors->create(['name' => 'Ada']));
        $bob = $authors->save($authors->create(['name' => 'Bob']));
        $authors->save($authors->create(['name' => 'Cyd']));
        $repo->save([
            $repo->create(['title' => 'a1', 'views' => 10, 'author' => $ada]),
            $repo->create(['title' => 'a2', 'views' => 0, 'author' => $ada]),
            $repo->create(['title' => 'b1', 'views' => 30, 'author' => $bob]),
        ]);
        if ($notes > 0) {
            $conn = $ds->getConnection();
            $body = str_repeat('x', 1000);
            $conn->transaction(function () use ($conn, $notes, $body): void {
                for ($i = 0; $i < $notes; $i++) {
                    $conn->execute('INSERT INTO ' . $conn->quote('da_notes') . ' (title, body, views) VALUES (:t, :b, :v)', ['t' => "bulk $i", 'b' => $body . $i, 'v' => 100 + $i]);
                }
            });
        }
    }

    private static function sqlOf(Connection $conn, string $needle): int
    {
        return count(array_filter($conn->getQueryLog(), fn (array $q) => str_contains($q['sql'], $needle)));
    }

    // ---- replication --------------------------------------------------------------------------------

    public function testReplicationRoutesReadsToReplicasAndWritesToTheMaster(): void
    {
        $ds = $this->daDataSource(self::DA_TABLES, ['synchronize' => true, 'replication' => ['master' => $this->daNodeOptions(), 'slaves' => [$this->daNodeOptions()]]]);
        $master = $ds->getConnection();
        self::daSeed($ds);
        $slave = $ds->slaveConnection();
        $this->assertNotSame($master, $slave);
        $this->assertNotSame($master->pdo(), $slave->pdo(), 'every node has its own PDO');
        if (static::platform() !== 'sqlite') {
            $this->assertNotSame($this->backendId($master), $this->backendId($slave));
        }
        $master->enableQueryLog()->flushQueryLog();
        $slave->enableQueryLog()->flushQueryLog();
        $notes = $ds->getRepository(DaNote::class);
        $table = $master->quote('da_notes');

        $this->assertCount(3, $notes->find(['relations' => ['author']]));
        $this->assertSame(3, $notes->count());
        $this->assertCount(3, $ds->createQueryBuilder(DaNote::class, 'n')->getRawMany());
        $this->assertSame(3, self::sqlOf($slave, 'FROM ' . $table), 'find, count and getRawMany read the replica');
        $this->assertSame(1, self::sqlOf($slave, 'FROM ' . $master->quote('da_authors')), 'relations come from the same node');
        $this->assertSame(0, self::sqlOf($master, 'FROM ' . $table));

        $notes->save($notes->create(['title' => 'new']));
        $this->assertSame(1, self::sqlOf($master, 'INSERT INTO ' . $table));
        $this->assertSame(0, self::sqlOf($slave, 'INSERT'));

        $master->flushQueryLog();
        $slave->flushQueryLog();
        $ds->transaction(function (EntityManager $tx) use ($notes): void {
            $tx->save($tx->create(DaNote::class, ['title' => 'in tx']));
            $this->assertCount(5, $tx->find(DaNote::class), 'the transaction sees its own write');
            $this->assertCount(5, $notes->find(), 'plain repositories read the master during a transaction');
        });
        $this->assertSame(0, self::sqlOf($slave, 'FROM ' . $table));
        $this->assertGreaterThanOrEqual(2, self::sqlOf($master, 'FROM ' . $table));

        $qr = $ds->createQueryRunner('slave');
        $this->assertSame($slave, $qr->connection());
        $this->assertSame(5, (int) $qr->query('SELECT COUNT(*) AS n FROM ' . $table)[0]['n']);
        $qr->release();

        if (static::platform() !== 'sqlite') {
            // worker mode: a replica the server dropped reconnects on its own, like the master
            $this->killFromAnotherConnection($this->backendId($slave));
            $this->assertCount(5, $notes->find());
            $this->killFromAnotherConnection($this->backendId($master));
            $notes->save($notes->create(['title' => 'after reconnect']));
            $this->assertSame(6, $notes->count());
        }
        $ds->destroy();
    }

    // ---- query result cache -------------------------------------------------------------------------

    public function testQueryResultCacheOnTheDatabase(): void
    {
        $ds = $this->daDataSource(self::DA_TABLES, ['synchronize' => true, 'cache' => ['duration' => 60_000]]);
        $this->assertTrue($ds->schema()->hasTable('query-result-cache'));
        $this->assertSame([], $ds->logSchema(), 'sync then diff is empty with the cache table');
        $this->assertNull($ds->generateMigration('Nothing'));
        self::daSeed($ds);
        $notes = $ds->getRepository(DaNote::class);
        $conn = $ds->getConnection();
        $rename = fn (string $to) => $conn->execute('UPDATE ' . $conn->quote('da_notes') . ' SET title = :t WHERE title = :f', ['t' => $to, 'f' => 'a1']);

        $titles = fn (array $notes) => array_map(fn (DaNote $n) => $n->title, $notes);
        $this->assertSame(['a1', 'a2', 'b1'], $titles($notes->find(['cache' => true, 'order' => ['id' => 'ASC']])));
        $this->assertSame(3, $notes->count(['cache' => ['id' => 'note-count']]));
        $this->assertSame([['t' => 'a1']], $ds->createQueryBuilder(DaNote::class, 'n')->select('n.title AS t')->where('n.views = :v', ['v' => 10])->cache('raw', 60_000)->getRawMany());
        $rename('changed');
        $notes->save($notes->create(['title' => 'x']));
        $this->assertSame(['a1', 'a2', 'b1'], $titles($notes->find(['cache' => true, 'order' => ['id' => 'ASC']])));
        $this->assertSame(3, $notes->count(['cache' => ['id' => 'note-count']]));
        $this->assertSame([['t' => 'a1']], $ds->createQueryBuilder(DaNote::class, 'n')->select('n.title AS t')->where('n.views = :v', ['v' => 10])->cache('raw', 60_000)->getRawMany());
        $ds->queryResultCache->remove(['note-count-count', 'raw']);
        $this->assertSame(4, $notes->count(['cache' => ['id' => 'note-count']]));
        $this->assertSame([['t' => 'changed']], $ds->createQueryBuilder(DaNote::class, 'n')->select('n.title AS t')->where('n.views = :v', ['v' => 10])->cache('raw', 60_000)->getRawMany());
        $ds->queryResultCache->clear();
        $this->assertSame(['changed', 'a2', 'b1', 'x'], $titles($notes->find(['cache' => true, 'order' => ['id' => 'ASC']])));
        $ds->destroy();
    }

    // ---- streaming ----------------------------------------------------------------------------------

    public function testStreamingReadsRowsWithoutBufferingTheResult(): void
    {
        $ds = $this->daDataSource(self::DA_TABLES, ['synchronize' => true]);
        self::daSeed($ds, 3000);
        $qb = fn () => $ds->createQueryBuilder(DaNote::class, 'n')->orderBy('n.id');

        gc_collect_cycles();
        $base = memory_get_usage();
        memory_reset_peak_usage();
        $rows = $qb()->getRawMany();
        $buffered = memory_get_peak_usage() - $base;
        $this->assertCount(3003, $rows);
        unset($rows);

        gc_collect_cycles();
        $base = memory_get_usage();
        memory_reset_peak_usage();
        $count = 0;
        $other = null;
        foreach ($qb()->stream(100) as $row) {
            if ($count === 10) {
                // the connection stays usable while the stream is open
                $other = $ds->getRepository(DaAuthor::class)->count();
            }
            $count++;
        }
        $streamed = memory_get_peak_usage() - $base;
        $this->assertSame(3003, $count);
        $this->assertSame(3, $other);
        $this->assertLessThan($buffered / 5, $streamed, "stream peak $streamed B vs getRawMany $buffered B");

        // entities, in batches, with relations loaded per batch
        $names = [];
        foreach ($ds->getRepository(DaNote::class)->findIterable(['where' => ['views' => ['<', 100]], 'relations' => ['author']], 2) as $note) {
            $names[] = $note->title . '/' . $note->author?->name;
        }
        $this->assertSame(['a1/Ada', 'a2/Ada', 'b1/Bob'], $names);

        // inside a transaction the stream sees its uncommitted rows; leaving early frees the connection
        $ds->transaction(function (EntityManager $tx): void {
            $tx->save($tx->create(DaNote::class, ['title' => 'uncommitted', 'views' => -1]));
            $titles = [];
            foreach ($tx->createQueryBuilder(DaNote::class, 'n')->where('n.views < 1')->orderBy('n.views')->getManyIterable(1) as $note) {
                $titles[] = $note->title;
            }
            $this->assertSame(['uncommitted', 'a2'], $titles);
            foreach ($tx->createQueryBuilder(DaNote::class, 'n')->stream(10) as $row) {
                break;
            }
            $this->assertSame(3004, $tx->count(DaNote::class));
        });
        foreach ($qb()->stream() as $row) {
            break;
        }
        $this->assertSame(3004, $ds->getRepository(DaNote::class)->count());
        $ds->destroy();
    }

    // ---- view entities ------------------------------------------------------------------------------

    public function testViewEntitiesAreSynchronizedAndRead(): void
    {
        $ds = $this->daDataSource([...self::DA_TABLES, ...self::DA_VIEWS]);
        $ds->synchronize();
        self::daSeed($ds);
        $this->assertSame([], $ds->logSchema(), 'sync then diff is empty with views');
        $views = Introspector::for($ds->getConnection())->viewNames();
        foreach (['da_author_stats', 'da_busy_authors', 'da_popular_notes', 'da_note_totals'] as $name) {
            $this->assertArrayHasKey($name, $views);
        }
        $this->assertSame(static::platform() === 'pgsql', $views['da_note_totals'], 'materialized on PostgreSQL only');

        $stats = $ds->getRepository(DaAuthorStats::class)->find(['order' => ['name' => 'ASC']]);
        $this->assertSame([['Ada', 2, 10], ['Bob', 1, 30], ['Cyd', 0, 0]], array_map(fn (DaAuthorStats $s) => [$s->name, $s->notes, $s->totalViews], $stats));
        $this->assertSame(['Ada'], array_map(fn (DaBusyAuthor $a) => $a->name, $ds->getRepository(DaBusyAuthor::class)->find()));
        $this->assertSame(['b1', 'a1'], array_map(fn (DaPopularNote $p) => $p->title, $ds->getRepository(DaPopularNote::class)->find(['order' => ['views' => 'DESC']])));
        if (static::platform() === 'pgsql') {
            $this->assertSame(0, $ds->getRepository(DaNoteTotals::class)->find()[0]->notes, 'a materialized view keeps its data until refreshed');
            $ds->manager->query('REFRESH MATERIALIZED VIEW ' . $ds->getConnection()->quote('da_note_totals'));
        }
        $totals = $ds->getRepository(DaNoteTotals::class)->find();
        $this->assertSame([3, 40], [$totals[0]->notes, $totals[0]->views]);
        $ds->destroy();

        // a changed view is recreated with the views depending on it; changing a column the views use works too
        $v2 = $this->daDataSource([DaAuthor::class, DaNoteV2::class, DaAuthorStatsV2::class, DaBusyAuthor::class, DaPopularNote::class, DaNoteTotals::class]);
        $log = $v2->logSchema();
        $this->assertNotEmpty(array_filter($log, fn (string $sql) => str_starts_with($sql, 'DROP VIEW IF EXISTS ' . $v2->getConnection()->quote('da_busy_authors'))));
        $v2->synchronize();
        $this->assertSame([], $v2->logSchema());
        $this->assertSame([['Ada', 1], ['Bob', 1], ['Cyd', 0]], array_map(fn (DaAuthorStatsV2 $s) => [$s->name, $s->notes], $v2->getRepository(DaAuthorStatsV2::class)->find(['order' => ['name' => 'ASC']])));
        $this->assertSame([], $v2->getRepository(DaBusyAuthor::class)->find());
        $v2->getRepository(DaNoteV2::class)->save($v2->getRepository(DaNoteV2::class)->create(['title' => str_repeat('t', 150), 'views' => 99]));
        $this->assertCount(3, $v2->getRepository(DaPopularNote::class)->find());
        $v2->destroy();
    }

    public function testViewEntitiesThroughGeneratedMigrations(): void
    {
        $ds = $this->daDataSource([...self::DA_TABLES, ...self::DA_VIEWS]);
        $class = 'CreateDataAccess' . ucfirst(static::platform()) . bin2hex(random_bytes(3));
        $code = (string) $ds->generateMigration($class);
        $this->assertStringContainsString("\$schema->createView('da_busy_authors', 'SELECT id, name FROM da_author_stats WHERE note_count > 1');", $code);
        $this->assertStringContainsString("\$schema->createView('da_note_totals', ", $code);
        $this->assertSame(static::platform() === 'pgsql', str_contains($code, 'materialized: true'));
        eval(substr($code, 5));
        $ds->destroy();

        $run = $this->daDataSource([...self::DA_TABLES, ...self::DA_VIEWS], ['migrations' => [new $class]]);
        $run->runMigrations();
        $this->assertSame([], $run->logSchema(), 'the generated migration matches the entities');
        self::daSeed($run);
        $this->assertCount(3, $run->getRepository(DaAuthorStats::class)->find());
        $run->revertLastMigration();
        $this->assertSame([], array_filter(array_keys(Introspector::for($run->getConnection())->viewNames()), fn (string $v) => str_starts_with($v, 'da_')));
        $this->assertFalse($run->schema()->hasTable('da_notes'));
        $run->schema()->dropTableIfExists('migrations');
        $run->destroy();
    }
}
