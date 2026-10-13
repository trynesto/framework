<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers;

use Nesto\Orm\Connection;
use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\LockNotSupportedOnGivenDriverException;
use Nesto\Orm\OptimisticLockVersionMismatchException;
use Nesto\Orm\PessimisticLockTransactionRequiredException;
use Nesto\Orm\QueryException;
use Nesto\Orm\Repository;
use Nesto\Tests\Orm\Fixtures\Events\Account;
use Nesto\Tests\Orm\Fixtures\Events\AccountEntry;
use Nesto\Tests\Orm\Fixtures\Events\RecordingSubscriber;

/**
 * Transactions (QueryRunner, savepoints, isolation levels, events), upsert, version column and optimistic /
 * pessimistic locks on every driver, with the Events fixtures (tables ev_accounts / ev_entries, dropped before
 * and after each scenario). Pessimistic locks are checked from a second connection. Used by DriverTestCase.
 */
trait TransactionLockingScenarios
{
    private ?DataSource $bank = null;
    private ?Connection $second = null;

    private function bankDataSource(): DataSource
    {
        $ds = new DataSource(['entities' => [Account::class, AccountEntry::class]]);
        $ds->useConnection($this->ds->getConnection());
        $ds->initialize();
        $ds->synchronizer()->drop([Account::class, AccountEntry::class]);
        $ds->synchronize();

        return $this->bank = $ds;
    }

    #[\PHPUnit\Framework\Attributes\After]
    protected function dropBankTables(): void
    {
        if ($this->second !== null) {
            while ($this->second->inTransaction()) {
                $this->second->rollBack();
            }
            $this->second = null;
        }
        if ($this->bank !== null) {
            $conn = $this->bank->getConnection();
            while ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $conn->setTransactionListener(null)->setQueryListeners(null, null);
            $this->bank->synchronizer()->drop([Account::class, AccountEntry::class]);
            $this->bank = null;
        }
    }

    private function openAccount(Repository $accounts, string $owner, int $balance = 0): Account
    {
        return $accounts->save($accounts->create(['owner' => $owner, 'email' => strtolower($owner) . '@x.io', 'balance' => $balance]));
    }

    public function testTransactionsQueryRunnersAndIsolationLevels(): void
    {
        $ds = $this->bankDataSource();
        $accounts = $ds->getRepository(Account::class);
        $subscriber = new RecordingSubscriber(null);
        $ds->addSubscriber($subscriber);

        $qr = $ds->createQueryRunner();
        $qr->startTransaction();
        $qr->manager->save($qr->manager->create(Account::class, ['owner' => 'Ada', 'email' => 'ada@x.io']));
        $qr->startTransaction();
        $qr->manager->save($qr->manager->create(Account::class, ['owner' => 'Bob', 'email' => 'bob@x.io']));
        $qr->rollbackTransaction(); // savepoint
        $qr->commitTransaction();
        $qr->release();
        $this->assertSame(
            ['beforeTransactionStart', 'afterTransactionStart', 'beforeInsert', 'afterInsert', 'beforeTransactionStart', 'afterTransactionStart',
                'beforeInsert', 'afterInsert', 'beforeTransactionRollback', 'afterTransactionRollback', 'beforeTransactionCommit', 'afterTransactionCommit'],
            $subscriber->names(),
        );
        $this->assertSame(['Ada'], array_map(fn (Account $a) => $a->owner, $accounts->find()));

        // a failing statement inside a nested transaction only rolls back the savepoint (PostgreSQL too)
        $ds->transaction(function (EntityManager $tx): void {
            try {
                $tx->transaction(fn (EntityManager $inner) => $inner->save($inner->create(Account::class, ['owner' => 'Dup', 'email' => 'ada@x.io'])));
                $this->fail('expected a unique violation');
            } catch (QueryException) {
            }
            $tx->save($tx->create(Account::class, ['owner' => 'Cyd', 'email' => 'cyd@x.io']));
        });
        $this->assertSame(2, $accounts->count());

        $platform = static::platform();
        $levels = $platform === 'sqlite' ? ['READ UNCOMMITTED', 'SERIALIZABLE'] : Connection::ISOLATION_LEVELS;
        foreach ($levels as $level) {
            $seen = $ds->transaction($level, function (EntityManager $tx) use ($platform): string {
                $conn = $tx->getConnection();
                $tx->count(Account::class);
                if (! in_array($platform, ['pgsql', 'sqlite'], true)) {
                    usleep(120_000); // InnoDB refreshes information_schema.innodb_trx at most every 100 ms
                }

                return strtoupper(str_replace(['-', '_'], ' ', (string) match ($platform) {
                    'pgsql' => $conn->fetchColumn('SHOW transaction_isolation'),
                    'sqlite' => (int) $conn->fetchColumn('PRAGMA read_uncommitted') === 1 ? 'READ UNCOMMITTED' : 'SERIALIZABLE',
                    default => $conn->fetchColumn('SELECT trx_isolation_level FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = CONNECTION_ID()'),
                }));
            });
            $this->assertSame($level, $seen, "$platform $level");
        }
        $this->assertFalse($ds->getConnection()->inTransaction());
    }

    public function testUpsertVersionAndOptimisticLock(): void
    {
        $ds = $this->bankDataSource();
        $accounts = $ds->getRepository(Account::class);
        $ada = $this->openAccount($accounts, 'Ada', 10);
        $this->assertSame(1, $ada->version);

        $result = $accounts->upsert([
            ['owner' => 'Ada L', 'email' => 'ada@x.io', 'balance' => 15, 'slug' => 'ada l'],
            ['owner' => 'Bob', 'email' => 'bob@x.io', 'balance' => 1],
        ], ['email']);
        $bob = $accounts->findOneByOrFail(['email' => 'bob@x.io']);
        $this->assertSame([['id' => $ada->id], ['id' => $bob->id]], $result->identifiers);
        $this->assertSame([2, 1], array_map(fn (array $m) => $m['version'], $result->generatedMaps));
        $fresh = $accounts->findOneByOrFail(['id' => $ada->id]);
        $this->assertSame(['Ada L', 15, 2], [$fresh->owner, $fresh->balance, $fresh->version]);

        // unchanged values: the row (update date, version) is left alone
        $stamp = $fresh->updatedAt;
        $entity = $accounts->create(['owner' => 'Ada L', 'email' => 'ada@x.io', 'balance' => 15]);
        $accounts->upsert($entity, ['conflictPaths' => ['email'], 'skipUpdateIfNoValuesChanged' => true]);
        $this->assertSame([$ada->id, 2], [$entity->id, $entity->version]);
        $this->assertEquals($stamp, $accounts->findOneByOrFail(['id' => $ada->id])->updatedAt);
        $accounts->upsert(['owner' => 'Ada', 'email' => 'ada@x.io', 'balance' => 15], ['conflictPaths' => ['email'], 'skipUpdateIfNoValuesChanged' => true]);
        $this->assertSame(['Ada', 3], [($row = $accounts->findOneByOrFail(['id' => $ada->id]))->owner, $row->version]);

        // the version moves with every save / update; optimistic locks compare it
        $row->balance = 20;
        $accounts->save($row);
        $this->assertSame(4, $row->version);
        $accounts->update($row->id, ['balance' => 21]);
        $this->assertNotNull($accounts->findOne(['where' => ['id' => $row->id], 'lock' => ['mode' => 'optimistic', 'version' => 5]]));
        try {
            $accounts->findOne(['where' => ['id' => $row->id], 'lock' => ['mode' => 'optimistic', 'version' => 4]]);
            $this->fail('expected a version mismatch');
        } catch (OptimisticLockVersionMismatchException $e) {
            $this->assertSame(5, $e->actualVersion);
        }
        $loaded = $accounts->findOneByOrFail(['id' => $row->id]);
        $this->assertNotNull($accounts->createQueryBuilder('a')->where(['id' => $row->id])->setLock('optimistic', $loaded->updatedAt)->getOne());

        // aggregates
        $this->assertSame(22.0, $accounts->sum('balance'));
        $this->assertSame(11.0, $accounts->average('balance'));
        $this->assertSame(1, $accounts->minimum('balance'));
        $this->assertSame(21, $accounts->maximum('balance'));
    }

    public function testPessimisticLocks(): void
    {
        $ds = $this->bankDataSource();
        $accounts = $ds->getRepository(Account::class);
        $ada = $this->openAccount($accounts, 'Ada');
        $this->openAccount($accounts, 'Bob');
        try {
            $accounts->findOne(['where' => ['id' => $ada->id], 'lock' => ['mode' => 'pessimistic_write']]);
            $this->fail('expected PessimisticLockTransactionRequiredException');
        } catch (PessimisticLockTransactionRequiredException) {
        }
        $platform = static::platform();
        if ($platform === 'sqlite') {
            $this->expectException(LockNotSupportedOnGivenDriverException::class);
            $ds->transaction(fn (EntityManager $tx) => $tx->find(Account::class, ['lock' => ['mode' => 'pessimistic_read']]));

            return;
        }

        // a second session sees the rows the first one locked
        $this->second = Connection::fromOptions(static::options());
        $this->second->execute($platform === 'pgsql' ? "SET lock_timeout = '2s'" : 'SET SESSION innodb_lock_wait_timeout = 2');
        $other = (new DataSource(['entities' => [Account::class, AccountEntry::class]]))->useConnection($this->second);
        $otherAccounts = $other->getRepository(Account::class);

        $conn = $ds->getConnection();
        $conn->enableQueryLog()->flushQueryLog();
        $ds->transaction(function (EntityManager $tx) use ($ada, $otherAccounts, $other, $conn, $platform): void {
            $locked = $tx->findOne(Account::class, ['where' => ['id' => $ada->id], 'lock' => ['mode' => 'pessimistic_write']]);
            $this->assertSame('Ada', $locked->owner);
            $this->assertStringEndsWith(' FOR UPDATE', $conn->getQueryLog()[0]['sql']);

            $other->transaction(function (EntityManager $otherTx) use ($ada, $otherAccounts, $platform): void {
                // SKIP LOCKED: the locked row is skipped
                $this->assertSame(['Bob'], array_map(fn (Account $a) => $a->owner, $otherAccounts->find(['lock' => ['mode' => 'pessimistic_write', 'onLocked' => 'skip_locked']])));
                $this->assertSame(['Bob'], array_map(fn (Account $a) => $a->owner, $otherAccounts->find(['lock' => ['mode' => 'pessimistic_partial_write']])));
                $skipped = $otherTx->createQueryBuilder(Account::class, 'a')->setLock('pessimistic_write')->setOnLocked('skip_locked')->getMany();
                $this->assertCount(1, $skipped);
                // NOWAIT: fails at once instead of waiting
                foreach ([['mode' => 'pessimistic_write', 'onLocked' => 'nowait'], ['mode' => 'pessimistic_write_or_fail']] as $lock) {
                    try {
                        $otherTx->transaction(fn (EntityManager $inner) => $inner->findOne(Account::class, ['where' => ['id' => $ada->id], 'lock' => $lock]));
                        $this->fail('expected the locked row to fail with NOWAIT: ' . json_encode($lock));
                    } catch (QueryException) {
                    }
                }
                if ($platform !== 'mariadb') { // MariaDB's LOCK IN SHARE MODE has no NOWAIT
                    try {
                        $otherTx->transaction(fn (EntityManager $inner) => $inner->findOne(Account::class, ['where' => ['id' => $ada->id], 'lock' => ['mode' => 'pessimistic_read', 'onLocked' => 'nowait']]));
                        $this->fail('a shared lock conflicts with FOR UPDATE');
                    } catch (QueryException) {
                    }
                }
            });
        });
        $this->assertFalse($this->second->inTransaction());

        // PostgreSQL-only modes and lock tables
        $ds->transaction(function (EntityManager $tx) use ($platform, $conn): void {
            $qb = fn (string $mode, ?array $tables = null) => $tx->createQueryBuilder(Account::class, 'a')->leftJoinAndSelect('a.entries', 'en')->setLock($mode, null, $tables);
            if ($platform === 'pgsql') {
                $this->assertStringEndsWith(' FOR UPDATE OF "a"', $qb('pessimistic_write', ['a'])->getSql());
                $this->assertStringEndsWith(' FOR NO KEY UPDATE OF "a"', $qb('for_no_key_update', ['ev_accounts'])->getSql());
                $this->assertStringEndsWith(' FOR KEY SHARE OF "a" NOWAIT', $qb('for_key_share', ['a'])->setOnLocked('nowait')->getSql());
                $this->assertStringEndsWith(' FOR SHARE', $tx->createQueryBuilder(Account::class, 'a')->setLock('pessimistic_read')->getSql());
                $this->assertCount(2, $qb('pessimistic_write', ['a'])->getMany());
                $conn->flushQueryLog();
                $this->assertCount(2, $tx->find(Account::class, ['relations' => ['entries'], 'relationLoadStrategy' => 'join', 'lock' => ['mode' => 'pessimistic_write', 'tables' => ['ev_accounts']]]));
                $this->assertStringEndsWith(' FOR UPDATE OF "e"', $conn->getQueryLog()[0]['sql']);
                $this->assertCount(2, $tx->find(Account::class, ['lock' => ['mode' => 'for_no_key_update']]));

                return;
            }
            $this->assertStringEndsWith($platform === 'mysql' ? ' FOR SHARE' : ' LOCK IN SHARE MODE', $tx->createQueryBuilder(Account::class, 'a')->setLock('pessimistic_read')->getSql());
            $this->assertCount(2, $tx->find(Account::class, ['lock' => ['mode' => 'pessimistic_read']]));
            foreach (['for_no_key_update', 'for_key_share'] as $mode) {
                try {
                    $tx->find(Account::class, ['lock' => ['mode' => $mode]]);
                    $this->fail("$mode is PostgreSQL-only");
                } catch (LockNotSupportedOnGivenDriverException) {
                }
            }
            $this->expectException(LockNotSupportedOnGivenDriverException::class);
            $tx->find(Account::class, ['lock' => ['mode' => 'pessimistic_write', 'tables' => ['ev_accounts']]]);
        });
    }
}
