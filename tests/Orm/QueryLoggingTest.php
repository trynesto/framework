<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\DataSource;
use Nesto\Orm\Logging\AbstractLogger;
use Nesto\Orm\Logging\AdvancedConsoleLogger;
use Nesto\Orm\Logging\DebugLogger;
use Nesto\Orm\Logging\FileLogger;
use Nesto\Orm\Logging\Logger;
use Nesto\Orm\Logging\LoggerFactory;
use Nesto\Orm\Logging\NullLogger;
use Nesto\Orm\Logging\PsrLogger;
use Nesto\Orm\Logging\SimpleConsoleLogger;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\Migration\Blueprint;
use Nesto\Orm\Migration\Migration;
use Nesto\Orm\Migration\Schema;
use Nesto\Orm\OrmException;
use Nesto\Orm\QueryException;
use Nesto\Orm\QueryRunner;
use Nesto\Tests\Orm\Fixtures\Tag;
use Nesto\Tests\Orm\Fixtures\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger as PsrAbstractLogger;

/** TypeORM's `logging` / `logger` / `maxQueryExecutionTime` options. */
final class QueryLoggingTest extends TestCase
{
    protected function setUp(): void
    {
        MetadataRegistry::clear();
    }

    private static function recorder(bool|string|array $logging = true): Logger
    {
        return new class ($logging) extends AbstractLogger {
            /** @var list<array{0: string, 1: list<array<string, mixed>>}> */
            public array $entries = [];

            protected function writeLog(string $level, array $messages, ?QueryRunner $queryRunner = null): void
            {
                $this->entries[] = [$level, $this->prepareLogMessages($messages)];
            }

            /** @return list<string> "type: message" lines */
            public function lines(): array
            {
                $lines = [];
                foreach ($this->entries as [, $messages]) {
                    foreach ($messages as $m) {
                        $lines[] = $m['type'] . ': ' . (isset($m['prefix']) ? $m['prefix'] . ' ' : '') . $m['message'];
                    }
                }

                return $lines;
            }
        };
    }

    private static function grep(array $lines, string $needle): array
    {
        return array_values(array_filter($lines, fn (string $l) => str_contains($l, $needle)));
    }

    public function testQueriesErrorsAndTransactionsGoToTheConfiguredLogger(): void
    {
        $logger = self::recorder(['query', 'error']);
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [Tag::class], 'synchronize' => true, 'logger' => $logger]);
        $ds->initialize();
        $this->assertSame($logger, $ds->logger);
        $logger->entries = [];

        $tags = $ds->getRepository(Tag::class);
        $tags->save($tags->create(['name' => 'php']));
        $tags->findOneBy(['name' => 'php']);
        try {
            $tags->query('SELECT nope FROM tags WHERE id = :id', ['id' => 7]);
            $this->fail('expected a query error');
        } catch (QueryException) {
        }

        $lines = $logger->lines();
        $this->assertNotEmpty(self::grep($lines, 'query: query: START TRANSACTION'));
        $this->assertNotEmpty(self::grep($lines, 'query: query: COMMIT'));
        $insert = self::grep($lines, 'INSERT INTO "tags"');
        $this->assertCount(1, $insert);
        $this->assertStringContainsString('-- PARAMETERS: ', $insert[0]);
        $this->assertStringContainsString('"php"', $insert[0]); // parameters are shown, like TypeORM
        $this->assertNotEmpty(self::grep($lines, 'SELECT "e"."id" AS "e__id"'));
        $this->assertSame(['query-error: query failed: SELECT nope FROM tags WHERE id = :id -- PARAMETERS: {"id":7}'], self::grep($lines, 'query failed'));
        $this->assertCount(1, self::grep($lines, 'query-error: error: SQLSTATE'));
    }

    public function testLoggingLevelsFilterTheEvents(): void
    {
        $errorsOnly = self::recorder(['error']);
        $this->assertFalse($errorsOnly->isLogEnabledFor('query'));
        $this->assertTrue($errorsOnly->isLogEnabledFor('query-error'));
        $this->assertTrue($errorsOnly->isLogEnabledFor('query-slow'), 'slow queries are always logged');
        $this->assertTrue($errorsOnly->isLogEnabledFor('migration'), 'migrations are always logged');
        $this->assertFalse($errorsOnly->isLogEnabledFor('schema'));

        $true = self::recorder(true);
        $this->assertTrue($true->isLogEnabledFor('query'));
        $this->assertTrue($true->isLogEnabledFor('error'));
        $this->assertFalse($true->isLogEnabledFor('schema'), 'TypeORM: logging true does not include schema / info / warn / log');
        $this->assertFalse($true->isLogEnabledFor('warn'));

        $all = self::recorder('all');
        foreach (['query', 'error', 'schema', 'warn', 'info', 'log', 'migration'] as $level) {
            $this->assertTrue($all->isLogEnabledFor($level), $level);
        }
        $off = self::recorder(false);
        $off->log('info', 'hidden');
        $off->logQuery('SELECT 1');
        $off->logMigration('shown');
        $this->assertSame(['migration: shown'], $off->lines());

        $info = self::recorder(['info', 'warn']);
        $info->log('info', 'hello');
        $info->log('warn', ['a' => 1]);
        $info->log('log', 'not enabled');
        $this->assertSame(['info: info: hello', 'warn: {"a":1}'], $info->lines());
    }

    public function testSlowQueriesAreReportedAboveMaxQueryExecutionTime(): void
    {
        $logger = self::recorder(false);
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'logger' => $logger, 'maxQueryExecutionTime' => 0]);
        $ds->getConnection()->pdo()->sqliteCreateFunction('nesto_sleep', function (int $ms): int {
            usleep($ms * 1000);

            return $ms;
        });
        $ds->manager->query('SELECT nesto_sleep(:ms) AS slept', ['ms' => 3]);
        $slow = self::grep($logger->lines(), 'query is slow');
        $this->assertCount(1, $slow);
        $this->assertStringContainsString('SELECT nesto_sleep(:ms) AS slept -- PARAMETERS: {"ms":3}', $slow[0]);
        $time = $logger->entries[0][1][0]['additionalInfo']['time'];
        $this->assertGreaterThanOrEqual(3, $time);
        $this->assertSame('query-slow: execution time: ' . $time, $logger->lines()[1]);

        $fast = self::recorder(false);
        $ds2 = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'logger' => $fast, 'maxQueryExecutionTime' => 60_000]);
        $ds2->manager->query('SELECT 1');
        $this->assertSame([], $fast->entries);
    }

    public function testSchemaBuildAndMigrationEvents(): void
    {
        $logger = self::recorder(['schema']);
        $ds = new DataSource([
            'type' => 'sqlite', 'database' => ':memory:', 'entities' => [User::class], 'synchronize' => true, 'logger' => $logger,
            'migrations' => [new class extends Migration {
                public function name(): string
                {
                    return 'CreateNotes1700000000000';
                }

                public function up(Schema $schema): void
                {
                    $schema->createTable('notes', fn (Blueprint $t) => $t->id());
                }

                public function down(Schema $schema): void
                {
                    $schema->dropTable('notes');
                }
            }],
        ]);
        $ds->initialize();
        $this->assertContains('schema-build: creating a new table: users', $logger->lines());
        $ds->runMigrations();
        $ds->runMigrations();
        $ds->revertLastMigration();
        $migrations = self::grep($logger->lines(), 'migration: ');
        $this->assertSame([
            'migration: 1 migrations are new migrations must be executed.',
            'migration: Migration CreateNotes1700000000000 has been executed successfully.',
            'migration: No migrations are pending',
            'migration: Migration CreateNotes1700000000000 has been reverted successfully.',
        ], $migrations);
        $this->assertSame([], self::grep($logger->lines(), 'query:'), 'queries are not enabled');
    }

    public function testBuiltInLoggersAndFactory(): void
    {
        $this->assertInstanceOf(NullLogger::class, LoggerFactory::fromOptions([]));
        $this->assertInstanceOf(NullLogger::class, LoggerFactory::fromOptions(['logging' => false]));
        $this->assertInstanceOf(NullLogger::class, LoggerFactory::fromOptions(['logging' => fn () => null]));
        $this->assertInstanceOf(AdvancedConsoleLogger::class, LoggerFactory::fromOptions(['logging' => true]));
        $this->assertInstanceOf(AdvancedConsoleLogger::class, LoggerFactory::fromOptions(['maxQueryExecutionTime' => 100]));
        $this->assertInstanceOf(SimpleConsoleLogger::class, LoggerFactory::fromOptions(['logger' => 'simple-console']));
        $this->assertInstanceOf(FileLogger::class, LoggerFactory::fromOptions(['logger' => 'file', 'logging' => ['error']]));
        $this->assertInstanceOf(DebugLogger::class, LoggerFactory::fromOptions(['logger' => 'debug']));
        $this->expectException(OrmException::class);
        LoggerFactory::create('nope');
    }

    public function testConsoleLoggersWriteToTheGivenStream(): void
    {
        $stream = fopen('php://memory', 'w+b');
        $logger = new SimpleConsoleLogger(true, $stream);
        $logger->logQuery('SELECT * FROM "users" WHERE "id" = :id', ['id' => 3]);
        $logger->logQueryError(new \RuntimeException('boom'), 'SELECT x', []);
        $advanced = new AdvancedConsoleLogger('all', $stream, colors: false);
        $advanced->logQuerySlow(12.5, 'SELECT 2');
        $advanced->log('warn', 'careful');
        $colored = new AdvancedConsoleLogger(true, $stream, colors: true);
        $colored->logQuery('SELECT 1');
        rewind($stream);
        $lines = explode("\n", trim((string) stream_get_contents($stream)));
        $this->assertSame([
            'query: SELECT * FROM "users" WHERE "id" = :id -- PARAMETERS: {"id":3}',
            'query failed: SELECT x',
            'error: boom',
            'query is slow: SELECT 2',
            'execution time: 12.5',
            'careful',
        ], array_slice($lines, 0, 6));
        $this->assertStringContainsString("\033[", $lines[6]);
        $this->assertStringContainsString('SELECT', $lines[6]);
    }

    public function testFileLoggerFormat(): void
    {
        $dir = sys_get_temp_dir() . '/nesto-orm-log-' . bin2hex(random_bytes(4));
        $logger = new FileLogger(['query', 'error'], ['logPath' => $dir . '/orm.log']);
        $logger->logQuery('SELECT 1', [1]);
        $logger->logQueryError('bad', 'SELECT y');
        $logger->logQuerySlow(30, 'SELECT z');
        $logger->logMigration('Migration X has been executed successfully.');
        $logger->log('info', 'skipped');
        $content = (string) file_get_contents($logger->path());
        @unlink($logger->path());
        @rmdir($dir);
        $lines = array_map(fn (string $l) => (string) preg_replace('/^\[[^\]]+\]/', '', $l), explode("\n", trim($content)));
        $this->assertSame([
            '[QUERY]: SELECT 1 -- PARAMETERS: [1]',
            '[FAILED QUERY]: SELECT y',
            '[QUERY ERROR]: bad',
            '[SLOW QUERY: 30 ms]: SELECT z',
            'Migration X has been executed successfully.',
        ], $lines);
        $this->assertMatchesRegularExpression('/^\[\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z\]/', $content);
    }

    public function testDebugLoggerFollowsTheDebugVariable(): void
    {
        $this->assertTrue(DebugLogger::enabled('typeorm:query', 'typeorm:*'));
        $this->assertTrue(DebugLogger::enabled('typeorm:query:slow', 'typeorm:query*'));
        $this->assertFalse(DebugLogger::enabled('typeorm:migration', 'typeorm:query*'));
        $this->assertFalse(DebugLogger::enabled('typeorm:query', 'typeorm:*,-typeorm:query'));
        $this->assertFalse(DebugLogger::enabled('typeorm:query', ''));

        $stream = fopen('php://memory', 'w+b');
        $logger = new DebugLogger('typeorm:query*', $stream);
        $logger->logQuery('SELECT 1');
        $logger->logQuerySlow(5, 'SELECT 2');
        $logger->logMigration('hidden');
        rewind($stream);
        $this->assertSame("typeorm:query SELECT 1\ntypeorm:query:slow SELECT 2 -- EXECUTION TIME: 5\n", stream_get_contents($stream));
    }

    public function testPsrLoggerReceivesLevelsAndContext(): void
    {
        $psr = new class extends PsrAbstractLogger {
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'logger' => $psr, 'logging' => ['query', 'error']]);
        $this->assertInstanceOf(PsrLogger::class, $ds->logger);
        $ds->manager->query('SELECT :a AS a', ['a' => 1]);
        try {
            $ds->manager->query('SELECT broken(');
        } catch (QueryException) {
        }
        $this->assertSame('debug', $psr->records[0][0]);
        $this->assertSame('query: SELECT :a AS a -- PARAMETERS: {"a":1}', $psr->records[0][1]);
        $this->assertSame(['query' => 'SELECT :a AS a', 'parameters' => ['a' => 1]], $psr->records[0][2]);
        $this->assertSame('error', $psr->records[2][0]);
        $this->assertStringStartsWith('query failed: SELECT broken( error: ', $psr->records[2][1]);
    }

    public function testLegacyLoggingClosureStillReceivesEveryStatement(): void
    {
        $seen = [];
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'logging' => function (string $sql, array $params, float $time) use (&$seen): void {
            $seen[] = $sql;
        }]);
        $ds->manager->query('SELECT 1');
        $this->assertSame(['SELECT 1'], $seen);
        $this->assertInstanceOf(NullLogger::class, $ds->logger);
    }
}
