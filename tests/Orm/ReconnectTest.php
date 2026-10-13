<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\Connection;
use Nesto\Orm\QueryException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/** A PDO whose server "went away": every prepare() fails the way mysqlnd reports it. */
final class GoneAwayPdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
    }
}

final class ReconnectTest extends TestCase
{
    public function testQueryReconnectsOnceWhenTheServerDroppedTheConnection(): void
    {
        $opened = 0;
        $conn = new Connection(new GoneAwayPdo, 'mysql', function () use (&$opened): PDO {
            $opened++;

            return new PDO('sqlite::memory:');
        });

        self::assertSame(1, (int) $conn->fetchColumn('SELECT 1'));
        self::assertSame(1, $opened);
        self::assertSame(2, (int) $conn->fetchColumn('SELECT 2'));
        self::assertSame(1, $opened, 'a healthy connection is reused');
    }

    public function testNoRetryInsideATransaction(): void
    {
        $conn = new Connection(new PDO('sqlite::memory:'), 'mysql', fn (): PDO => self::fail('must not reconnect mid-transaction'));
        $conn->beginTransaction();

        $dead = new \ReflectionProperty(Connection::class, 'pdo');
        $dead->setValue($conn, new GoneAwayPdo);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('gone away');
        $conn->fetchColumn('SELECT 1');
    }

    public function testOtherErrorsAreNotRetried(): void
    {
        $conn = new Connection(new PDO('sqlite::memory:'), 'mysql', fn (): PDO => self::fail('must not reconnect on a SQL error'));

        $this->expectException(QueryException::class);
        $conn->fetchColumn('SELECT * FROM missing_table');
    }

    public function testWithoutReconnectorTheErrorSurfaces(): void
    {
        $conn = new Connection(new GoneAwayPdo, 'mysql');

        $this->expectException(QueryException::class);
        $conn->fetchColumn('SELECT 1');
    }
}
