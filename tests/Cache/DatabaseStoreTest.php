<?php

declare(strict_types=1);

namespace Nesto\Tests\Cache;

use Nesto\Cache\Stores\DatabaseStore;
use Nesto\Orm\Connection;
use Nesto\Orm\Migration\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** DatabaseStore on SQLite, and on PostgreSQL / MySQL / MariaDB when NESTO_TEST_*_URL are set. */
final class DatabaseStoreTest extends TestCase
{
    /** @var list<array{0: Connection, 1: string}> */
    private array $tables = [];

    protected function tearDown(): void
    {
        foreach ($this->tables as [$connection, $table]) {
            (new Schema($connection))->dropTable($table, ifExists: true);
        }
        $this->tables = [];
    }

    /** @return iterable<string, array{0: string}> */
    public static function drivers(): iterable
    {
        yield 'sqlite' => ['sqlite::memory:'];
        yield 'pgsql' => ['NESTO_TEST_PGSQL_URL'];
        yield 'mysql' => ['NESTO_TEST_MYSQL_URL'];
        yield 'mariadb' => ['NESTO_TEST_MARIADB_URL'];
    }

    private function connection(string $source): Connection
    {
        if ($source === 'sqlite::memory:') {
            return Connection::fromOptions(['type' => 'sqlite', 'database' => ':memory:']);
        }
        $url = getenv($source);
        if ($url === false || $url === '') {
            self::markTestSkipped("$source not configured.");
        }

        return Connection::fromOptions(['url' => $url]);
    }

    private function store(Connection $connection, string $prefix = 'app_', ?int $ttl = null, array $lottery = [0, 100]): DatabaseStore
    {
        $table = 'nesto_cache_' . bin2hex(random_bytes(4));
        $this->tables[] = [$connection, $table];

        return new DatabaseStore($connection, $table, $prefix, $ttl, lottery: $lottery);
    }

    #[DataProvider('drivers')]
    public function testCreatesItsTableAndStoresValues(string $source): void
    {
        $connection = $this->connection($source);
        $store = $this->store($connection, ttl: 60);

        self::assertNull($store->get('missing'));
        self::assertTrue((new Schema($connection))->hasTable($store->table()), 'created on first use');

        $object = new \ArrayObject(['x' => "binary\0bytes", 'n' => 1.5]);
        self::assertTrue($store->set('object', $object));
        self::assertTrue($store->set('false', false));
        self::assertTrue($store->set('null', null));
        self::assertEquals($object, $store->get('object'));
        self::assertFalse($store->get('false', 'default'));
        self::assertNull($store->get('null', 'default'));
        self::assertTrue($store->has('null'));
        self::assertFalse($store->has('nope'));

        // upsert
        $store->set('object', 'replaced');
        self::assertSame('replaced', $store->get('object'));

        $row = $connection->fetchOne('SELECT ' . $connection->quote('expiration') . ' AS e FROM ' . $connection->quote($store->table()) . ' WHERE ' . $connection->quote('key') . " = 'app_object'");
        self::assertEqualsWithDelta(time() + 60, (int) $row['e'], 2, 'the default TTL');

        self::assertSame(['object' => 'replaced', 'false' => false, 'x' => 'd'], $store->getMultiple(['object', 'false', 'x'], 'd'));
        $store->deleteMultiple(['object', 'false']);
        self::assertNull($store->get('object'));
    }

    #[DataProvider('drivers')]
    public function testExpiryGarbageCollectionAndPrefixedClear(string $source): void
    {
        $connection = $this->connection($source);
        $store = $this->store($connection, prefix: 'a_%_');
        $other = new DatabaseStore($connection, $store->table(), 'b_');

        $store->set('forever', 1);
        $store->set('expired', 1, 1);
        $other->set('neighbour', 'kept');
        $connection->execute('UPDATE ' . $connection->quote($store->table()) . ' SET ' . $connection->quote('expiration') . ' = :e WHERE ' . $connection->quote('key') . " = 'a_%_expired'", ['e' => time() - 5]);

        self::assertSame(1, $store->gc());
        self::assertTrue($store->has('forever'), '0 = forever');

        $store->set('again', 2);
        $store->clear();
        self::assertFalse($store->has('forever'));
        self::assertSame('kept', $other->get('neighbour'), 'clear() keeps other prefixes (LIKE wildcards escaped)');
    }

    public function testExpiredRowIsDeletedWhenRead(): void
    {
        $connection = $this->connection('sqlite::memory:');
        $store = $this->store($connection, prefix: '');
        $store->set('old', 'value', 100);
        $connection->execute('UPDATE ' . $connection->quote($store->table()) . ' SET expiration = 1');

        self::assertSame('miss', $store->get('old', 'miss'));
        self::assertSame(0, (int) $connection->fetchColumn('SELECT COUNT(*) FROM ' . $connection->quote($store->table())));
    }

    public function testLazyConnectionFactory(): void
    {
        $calls = 0;
        $store = new DatabaseStore(function () use (&$calls) {
            $calls++;

            return Connection::fromOptions(['type' => 'sqlite', 'database' => ':memory:']);
        });
        self::assertSame(0, $calls);
        $store->set('k', 'v');
        self::assertSame('v', $store->get('k'));
        self::assertSame(1, $calls);
    }
}
