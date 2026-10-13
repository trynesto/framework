<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers;

use Nesto\Orm\Connection;
use Nesto\Orm\Migration\Blueprint;
use Nesto\Orm\Migration\Grammar;
use Nesto\Orm\OrmException;
use PDO;
use PHPUnit\Framework\TestCase;

/** Driver-specific option handling and DDL that can be checked without a database server. */
final class ConnectionOptionsTest extends TestCase
{
    public function testParseUrl(): void
    {
        $this->assertSame(
            ['type' => 'pgsql', 'host' => 'db.internal', 'port' => 6543, 'username' => 'app', 'password' => 'p@ss/word', 'database' => 'shop'],
            Connection::parseUrl('postgres://app:p%40ss%2Fword@db.internal:6543/shop'),
        );
        $this->assertSame(
            ['type' => 'mariadb', 'host' => '127.0.0.1', 'username' => 'root', 'database' => 'app', 'charset' => 'utf8'],
            Connection::parseUrl('mariadb://root@127.0.0.1/app?charset=utf8'),
        );
        $this->assertSame(['type' => 'mysql', 'host' => 'localhost'], Connection::parseUrl('mysql://localhost'));
        $this->assertSame(['type' => 'sqlite', 'database' => '/var/data/app.db'], Connection::parseUrl('sqlite:///var/data/app.db'));
        $this->assertSame(['type' => 'sqlite', 'database' => ':memory:'], Connection::parseUrl('sqlite://:memory:'));

        $this->expectException(OrmException::class);
        Connection::parseUrl('oracle://x/y');
    }

    public function testDefaultPorts(): void
    {
        $this->assertSame(5432, Connection::defaultPort('postgres'));
        $this->assertSame(5432, Connection::defaultPort('pgsql'));
        $this->assertSame(3306, Connection::defaultPort('mysql'));
        $this->assertSame(3306, Connection::defaultPort('mariadb'));
        $this->assertNull(Connection::defaultPort('sqlite'));
    }

    public function testFromOptionsUrlAndRawDsn(): void
    {
        $this->assertSame('sqlite', Connection::fromOptions(['url' => 'sqlite://:memory:'])->driver());
        // a raw PDO DSN decides the PDO driver even when `type` was left at its default
        $conn = Connection::fromOptions(['type' => 'mysql', 'url' => 'sqlite::memory:']);
        $this->assertSame('sqlite', $conn->driver());
        $this->assertSame('sqlite', $conn->platform());
        $this->assertSame('"a"."b"', $conn->quote('a.b'));
    }

    public function testPlatformAndDriver(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $maria = new Connection($pdo, 'mariadb');
        $this->assertSame('mysql', $maria->driver());
        $this->assertSame('mariadb', $maria->platform());
        $this->assertTrue($maria->isMariaDb());
        $this->assertSame('`t`', $maria->quote('t'));

        $pg = new Connection($pdo, 'postgres');
        $this->assertSame('pgsql', $pg->driver());
        $this->assertSame('pgsql', $pg->platform());

        // configured as mysql: detected from the server version (a SQLite PDO reports "3.x", so MySQL)
        $this->assertSame('mysql', (new Connection($pdo, 'mysql'))->platform());
        $this->assertSame('mariadb', (new Connection($pdo, 'mysql', null, 'mariadb'))->platform());

        $this->expectException(OrmException::class);
        new Connection($pdo, 'oracle');
    }

    public function testDdlDifferencesBetweenMysqlAndMariaDb(): void
    {
        $bp = new Blueprint('things');
        $bp->id();
        $bp->json('tags')->default([]);
        $bp->text('bio')->default('');
        $bp->integer('n')->unsigned();
        $bp->index(['n']);

        $pdo = new PDO('sqlite::memory:');
        $mysql = (new Grammar(new Connection($pdo, 'mysql', null, 'mysql')))->compileCreate($bp, ifNotExists: true);
        $this->assertStringContainsString("`tags` JSON NOT NULL DEFAULT ('[]')", $mysql[0]); // MySQL: expression default
        $this->assertStringContainsString("`bio` TEXT NOT NULL DEFAULT ('')", $mysql[0]);
        $this->assertStringContainsString('`n` INTEGER UNSIGNED NOT NULL', $mysql[0]);
        $this->assertSame('CREATE INDEX `idx_things_n` ON `things` (`n`)', $mysql[1]); // no IF NOT EXISTS on MySQL

        $maria = (new Grammar(new Connection($pdo, 'mariadb')))->compileCreate($bp, ifNotExists: true);
        $this->assertStringContainsString("`tags` JSON NOT NULL DEFAULT '[]'", $maria[0]);
        $this->assertSame('CREATE INDEX IF NOT EXISTS `idx_things_n` ON `things` (`n`)', $maria[1]);

        $pg = (new Grammar(new Connection($pdo, 'pgsql')))->compileCreate($bp, ifNotExists: true);
        $this->assertStringContainsString("\"tags\" JSONB NOT NULL DEFAULT '[]'", $pg[0]);
        $this->assertStringContainsString('"n" INTEGER NOT NULL', $pg[0]);
    }

    public function testAddingNotNullColumnsGetsAUsableDefaultOnPostgres(): void
    {
        $bp = new Blueprint('things');
        $bp->string('sku');
        $bp->integer('stock');
        $bp->boolean('on');
        $bp->datetime('seen_at');
        $sql = (new Grammar(new Connection(new PDO('sqlite::memory:'), 'pgsql')))->compileAddColumns($bp);
        $this->assertSame([
            'ALTER TABLE "things" ADD COLUMN "sku" VARCHAR(255) NOT NULL DEFAULT \'\'',
            'ALTER TABLE "things" ALTER COLUMN "sku" DROP DEFAULT', // the default only filled existing rows
            'ALTER TABLE "things" ADD COLUMN "stock" INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE "things" ALTER COLUMN "stock" DROP DEFAULT',
            'ALTER TABLE "things" ADD COLUMN "on" BOOLEAN NOT NULL DEFAULT FALSE',
            'ALTER TABLE "things" ALTER COLUMN "on" DROP DEFAULT',
            'ALTER TABLE "things" ADD COLUMN "seen_at" TIMESTAMP NOT NULL',
        ], $sql);
    }

    public function testLongIndexNamesAreShortened(): void
    {
        $name = Grammar::indexName('a_really_long_table_name_for_testing', ['first_long_column', 'second_long_column'], true);
        $this->assertLessThanOrEqual(63, strlen($name));
        $this->assertStringStartsWith('uq_a_really_long_table_name', $name);
        $this->assertSame($name, Grammar::indexName('a_really_long_table_name_for_testing', ['first_long_column', 'second_long_column'], true));
        $this->assertSame('idx_users_name', Grammar::indexName('users', ['name'], false));
    }

    public function testLostConnectionDetection(): void
    {
        $gone = new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        $gone->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
        $this->assertTrue(Connection::isLostConnection($gone));
        $killed = new \PDOException('SQLSTATE[70100]: <<Unknown error>>: 1927 Connection was killed');
        $killed->errorInfo = ['70100', 1927, 'Connection was killed'];
        $this->assertTrue(Connection::isLostConnection($killed));
        $pg = new \PDOException("SQLSTATE[57P01]: Admin shutdown: 7 FATAL:  terminating connection due to administrator command");
        $pg->errorInfo = ['57P01', 7, 'FATAL'];
        $this->assertTrue(Connection::isLostConnection($pg));

        $interrupted = new \PDOException('SQLSTATE[70100]: Query execution was interrupted');
        $interrupted->errorInfo = ['70100', 1317, 'Query execution was interrupted'];
        $this->assertFalse(Connection::isLostConnection($interrupted), 'KILL QUERY is not a lost connection');
        $dup = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        $dup->errorInfo = ['23000', 1062, 'Duplicate entry'];
        $this->assertFalse(Connection::isLostConnection($dup));
    }
}
