<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\Logging\AbstractLogger;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\OrmException;
use Nesto\Orm\QueryException;
use Nesto\Orm\QueryRunner;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaAuthor;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaNote;
use PHPUnit\Framework\TestCase;

/**
 * TypeORM's replication (master / slaves) on SQLite files: the master and each slave are different database
 * files holding different rows, so every result shows which node answered.
 */
final class ReplicationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        MetadataRegistry::clear();
        $this->dir = sys_get_temp_dir() . '/nesto-replication-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        foreach (['master', 'slave1', 'slave2'] as $node) {
            $ds = new DataSource(['type' => 'sqlite', 'database' => $this->file($node), 'entities' => [DaAuthor::class, DaNote::class], 'synchronize' => true]);
            $authors = $ds->getRepository(DaAuthor::class);
            $author = $authors->save($authors->create(['name' => "$node author"]));
            $notes = $ds->getRepository(DaNote::class);
            $notes->save($notes->create(['title' => "from $node", 'author' => $author]));
            $ds->destroy();
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function file(string $node): string
    {
        return $this->dir . '/' . $node . '.sqlite';
    }

    /** @param list<string> $slaves */
    private function dataSource(array $slaves = ['slave1'], array $extra = []): DataSource
    {
        return new DataSource([
            'type' => 'sqlite',
            'entities' => [DaAuthor::class, DaNote::class],
            'replication' => ($extra['replication'] ?? []) + [
                'master' => ['database' => $this->file('master')],
                'slaves' => array_map(fn (string $s) => ['database' => $this->file($s)], $slaves),
            ],
        ] + array_diff_key($extra, ['replication' => true]));
    }

    private static function titles(array $notes): array
    {
        return array_map(fn (DaNote $n) => $n->title, $notes);
    }

    public function testReadsGoToASlaveAndWritesToTheMaster(): void
    {
        $ds = $this->dataSource();
        $this->assertTrue($ds->isReplicated());
        $this->assertSame('slave', $ds->defaultReplicationModeForReads());
        $notes = $ds->getRepository(DaNote::class);

        $this->assertSame(['from slave1'], self::titles($notes->find()));
        $this->assertSame('from slave1', $notes->findOneBy(['id' => 1])?->title);
        $this->assertSame(1, $notes->count());
        $this->assertSame(['from slave1'], self::titles($ds->createQueryBuilder(DaNote::class, 'n')->getMany()));
        $this->assertSame('from slave1', $ds->createQueryBuilder(DaNote::class, 'n')->getOne()?->title);
        $this->assertSame([['title' => 'from slave1']], $ds->createQueryBuilder(DaNote::class, 'n')->select(['n.title AS title'])->getRawMany());
        $this->assertSame(1, $ds->createQueryBuilder(DaNote::class, 'n')->getCount());
        // relations are read from the same node as the root rows
        $this->assertSame('slave1 author', $notes->findOne(['where' => ['id' => 1], 'relations' => ['author']])?->author?->name);
        $this->assertSame('slave1 author', $notes->findOne(['where' => ['id' => 1], 'relations' => ['author'], 'relationLoadStrategy' => 'join'])?->author?->name);

        // writes go to the master; raw queries too (TypeORM's dataSource.query() uses the master)
        $notes->save($notes->create(['title' => 'written']));
        $notes->update(['title' => 'from master'], ['views' => 5]);
        $this->assertSame(['from master', 'written'], array_column($ds->manager->query('SELECT title FROM da_notes ORDER BY id'), 'title'));
        $this->assertSame(['from slave1'], self::titles($notes->find()), 'the slave did not get the write (no real replication here)');
        $this->assertSame(['from master', 'written'], self::titles($ds->getRepository(DaNote::class)->createQueryBuilder('n')->setQueryRunner($ds->createQueryRunner('master'))->orderBy('n.id')->getMany()));
    }

    public function testEverythingInATransactionRunsOnTheMaster(): void
    {
        $ds = $this->dataSource();
        $notes = $ds->getRepository(DaNote::class);
        $seen = $ds->transaction(function (EntityManager $tx) use ($notes): array {
            $tx->save($tx->create(DaNote::class, ['title' => 'in tx']));

            return [
                self::titles($tx->find(DaNote::class, ['order' => ['id' => 'ASC']])),
                self::titles($notes->find(['order' => ['id' => 'ASC']])),          // plain repository: the master has a transaction open
                $tx->getRepository(DaNote::class)->createQueryBuilder('n')->getCount(),
            ];
        });
        $this->assertSame([['from master', 'in tx'], ['from master', 'in tx'], 2], $seen);

        $qr = $ds->createQueryRunner();
        $this->assertSame('master', $qr->getReplicationMode());
        $qr->startTransaction();
        $this->assertSame(['from master', 'in tx'], self::titles($qr->manager->find(DaNote::class)));
        $qr->rollbackTransaction();
        $qr->release();
        $this->assertSame(['from slave1'], self::titles($notes->find()));
    }

    public function testSlaveQueryRunnersAndDefaultMode(): void
    {
        $ds = $this->dataSource(['slave1', 'slave2']);
        $slave = $ds->createQueryRunner('slave');
        $this->assertSame('slave', $slave->getReplicationMode());
        $title = $slave->query('SELECT title FROM da_notes')[0]['title'];
        $this->assertContains($title, ['from slave1', 'from slave2']);
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($title, $slave->query('SELECT title FROM da_notes')[0]['title'], 'a slave runner keeps its node');
            $this->assertSame([$title], self::titles($slave->manager->find(DaNote::class)));
            $this->assertSame([$title], self::titles($slave->manager->getRepository(DaNote::class)->find()));
            $this->assertSame([$title], self::titles($ds->createQueryBuilder(DaNote::class, 'n')->setQueryRunner($slave)->getMany()));
        }
        $slave->release();
        $this->assertSame('from slave2', $ds->slaveConnection(1)->fetchColumn('SELECT title FROM da_notes'));

        // reads pick slaves at random
        $seen = [];
        for ($i = 0; $i < 40 && count($seen) < 2; $i++) {
            $seen[$ds->getRepository(DaNote::class)->find()[0]->title] = true;
        }
        $this->assertEqualsCanonicalizing(['from slave1', 'from slave2'], array_keys($seen));

        $masterReads = $this->dataSource(extra: ['replication' => ['defaultMode' => 'master']]);
        $this->assertSame('master', $masterReads->defaultReplicationModeForReads());
        $this->assertSame(['from master'], self::titles($masterReads->getRepository(DaNote::class)->find()));
        $this->assertSame('from slave1', $masterReads->createQueryRunner('slave')->query('SELECT title FROM da_notes')[0]['title']);
    }

    public function testNodesOpenLazilyAndAliasesAreAccepted(): void
    {
        $ds = new DataSource([
            'type' => 'sqlite',
            'entities' => [DaNote::class, DaAuthor::class],
            'replication' => [
                'primary' => ['database' => $this->file('master')],
                'replicas' => [['database' => $this->dir . '/missing/dir/replica.sqlite']],
            ],
        ]);
        // the broken replica is never opened by writes / raw queries
        $ds->getRepository(DaNote::class)->save($ds->getRepository(DaNote::class)->create(['title' => 'x']));
        $this->assertSame(2, (int) $ds->manager->query('SELECT COUNT(*) AS n FROM da_notes')[0]['n']);
        try {
            $ds->getRepository(DaNote::class)->find();
            $this->fail('the replica is opened on the first read');
        } catch (\PDOException|QueryException $e) {
            $this->assertStringContainsString('unable to open', strtolower($e->getMessage()));
        }

        $noSlaves = new DataSource(['type' => 'sqlite', 'entities' => [DaNote::class, DaAuthor::class], 'replication' => ['master' => ['database' => $this->file('master')], 'slaves' => []]]);
        $this->assertSame('from master', $noSlaves->getRepository(DaNote::class)->find()[0]->title, 'without slaves reads use the master');
    }

    public function testInvalidReplicationOptions(): void
    {
        foreach ([
            ['replication' => ['slaves' => []]],
            ['replication' => ['master' => [], 'slaves' => ['a' => []]]],
            ['replication' => ['master' => [], 'defaultMode' => 'replica']],
        ] as $options) {
            try {
                new DataSource(['type' => 'sqlite'] + $options);
                $this->fail('expected an OrmException for ' . json_encode($options));
            } catch (OrmException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(OrmException::class);
        (new DataSource(['type' => 'sqlite', 'database' => ':memory:']))->createQueryRunner('replica');
    }

    public function testEventsAndTheLoggerSeeQueriesOfEveryNode(): void
    {
        $logger = new class (['query']) extends AbstractLogger {
            public array $queries = [];

            protected function writeLog(string $level, array $messages, ?QueryRunner $queryRunner = null): void
            {
                $this->queries[] = $messages[0]['message'];
            }
        };
        $ds = $this->dataSource(extra: ['logger' => $logger]);
        $ds->getRepository(DaNote::class)->find();
        $ds->manager->query('SELECT 42');
        $this->assertCount(1, array_filter($logger->queries, fn (string $q) => str_contains($q, 'FROM "da_notes"')));
        $this->assertContains('SELECT 42', $logger->queries);
    }
}
