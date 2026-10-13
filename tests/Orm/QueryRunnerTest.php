<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\EntityManager;
use Nesto\Orm\OrmException;
use Nesto\Orm\QueryRunner;
use Nesto\Orm\QueryRunnerAlreadyReleasedException;
use Nesto\Orm\TransactionNotStartedException;
use Nesto\Tests\Orm\Fixtures\User;

/** QueryRunner, DataSource / EntityManager transaction() with isolation levels, worker-mode cleanup. */
final class QueryRunnerTest extends OrmTestCase
{
    private function user(string $name): User
    {
        return $this->users->create(['name' => $name, 'email' => strtolower($name) . '@example.com']);
    }

    public function testManualTransactionWithTheRunnersManager(): void
    {
        $qr = $this->ds->createQueryRunner();
        $qr->connect();
        $qr->startTransaction();
        $this->assertTrue($qr->isTransactionActive());
        $qr->manager->save($this->user('Ada'));
        $qr->manager->getRepository(User::class)->save($this->user('Bob'));
        $this->assertSame($qr->manager, $qr->manager->getRepository(User::class)->manager);
        $this->assertSame($qr, $qr->manager->queryRunner);
        $qr->rollbackTransaction();
        $this->assertSame(0, $this->users->count());

        $qr->startTransaction();
        $qr->manager->save($this->user('Cyd'));
        $qr->commitTransaction();
        $qr->release();
        $this->assertSame(['Cyd'], array_map(fn (User $u) => $u->name, $this->users->find()));
        $this->assertTrue($qr->isReleased());
        $this->assertNull($this->ds->manager->queryRunner);
    }

    public function testNestedStartTransactionUsesSavepoints(): void
    {
        $qr = $this->ds->createQueryRunner();
        $qr->startTransaction();
        $qr->manager->save($this->user('Outer'));
        $qr->startTransaction();
        $this->assertSame(2, $qr->transactionDepth());
        $qr->manager->save($this->user('Inner'));
        $qr->rollbackTransaction();
        $qr->commitTransaction();
        $qr->release();
        $this->assertSame(['Outer'], array_map(fn (User $u) => $u->name, $this->users->find()));
    }

    public function testCommitWithoutTransactionAndUseAfterReleaseFail(): void
    {
        $qr = $this->ds->createQueryRunner();
        try {
            $qr->commitTransaction();
            $this->fail('expected TransactionNotStartedException');
        } catch (TransactionNotStartedException) {
        }
        $qr->release();
        $qr->release(); // twice is fine
        $this->expectException(QueryRunnerAlreadyReleasedException::class);
        $qr->query('SELECT 1');
    }

    public function testReleaseRollsBackWhatTheRunnerLeftOpen(): void
    {
        $qr = $this->ds->createQueryRunner();
        $qr->startTransaction();
        $qr->startTransaction();
        $qr->manager->save($this->user('Lost'));
        $qr->release();
        $this->assertFalse($this->ds->getConnection()->inTransaction());
        $this->assertSame(0, $this->users->count());
    }

    public function testAForgottenRunnerRollsBackWhenItGoesOutOfScope(): void
    {
        // e.g. a request handler that threw between startTransaction() and release(): nothing leaks to the next request
        (function (): void {
            $qr = $this->ds->createQueryRunner();
            $qr->startTransaction();
            $qr->manager->save($this->user('Leaked'));
        })();
        $this->assertFalse($this->ds->getConnection()->inTransaction());
        $this->assertSame(0, $this->users->count());
    }

    public function testRawQueries(): void
    {
        $qr = $this->ds->createQueryRunner();
        $this->users->save($this->user('Ada'));
        $this->assertSame([['n' => 1]], $qr->query('SELECT COUNT(*) AS n FROM users'));
        $this->assertSame(1, $qr->query('UPDATE users SET age = :age', ['age' => 3]));
        $this->assertSame(1, $this->users->query('DELETE FROM users WHERE age = :age', ['age' => 3]));
    }

    public function testEntityManagerTransactionWithIsolationLevel(): void
    {
        $result = $this->ds->manager->transaction('READ UNCOMMITTED', function (EntityManager $tx): string {
            $this->assertNotSame($this->ds->manager, $tx);
            $this->assertInstanceOf(QueryRunner::class, $tx->queryRunner);
            $this->assertSame(1, (int) $tx->getConnection()->fetchColumn('PRAGMA read_uncommitted'));
            $tx->save($this->user('Ada'));

            return 'done';
        });
        $this->assertSame('done', $result);
        $this->assertSame(1, $this->users->count());

        $this->ds->transaction('serializable', fn (EntityManager $tx) => $this->assertSame(0, (int) $tx->getConnection()->fetchColumn('PRAGMA read_uncommitted')));
    }

    public function testUnsupportedIsolationLevelsAreRejected(): void
    {
        try {
            $this->ds->transaction('READ COMMITTED', fn () => null);
            $this->fail('SQLite has no READ COMMITTED');
        } catch (OrmException $e) {
            $this->assertStringContainsString('SQLite only supports', $e->getMessage());
        }
        $this->expectExceptionMessage('Unknown isolation level');
        $this->ds->transaction('SNAPSHOT', fn () => null);
    }

    public function testTransactionOnARunnersManagerNestsOnThatRunner(): void
    {
        $qr = $this->ds->createQueryRunner();
        $qr->startTransaction();
        $qr->manager->transaction(function (EntityManager $tx) use ($qr): void {
            $this->assertSame($qr->manager, $tx);
            $this->assertSame(2, $qr->transactionDepth());
        });
        $this->assertSame(1, $qr->transactionDepth());
        $this->assertFalse($qr->isReleased(), 'a runner passed in is not released by transaction()');
        $qr->release();
        $this->expectException(QueryRunnerAlreadyReleasedException::class);
        $qr->manager->transaction(fn () => null);
    }

    public function testWithRepositoryBindsACustomRepositoryToTheTransaction(): void
    {
        $repository = $this->users;
        $this->ds->transaction(function (EntityManager $tx) use ($repository): void {
            $bound = $tx->withRepository($repository);
            $this->assertNotSame($repository, $bound);
            $this->assertSame($tx, $bound->manager);
            $this->assertSame($tx->queryRunner, $bound->getQueryRunner());
            $this->assertSame($bound, $tx->withRepository($bound));
        });
        $this->assertSame($this->ds->manager, $repository->manager);
    }

    public function testSaveJoinsAnOpenTransactionInsteadOfASavepoint(): void
    {
        $this->ds->addSubscriber($subscriber = new \Nesto\Tests\Orm\Fixtures\Events\RecordingSubscriber(null));
        $this->ds->transaction(fn (EntityManager $tx) => $tx->save([$this->user('Ada'), $this->user('Eve')]));
        $this->assertSame(['beforeTransactionStart', 'afterTransactionStart', 'beforeInsert', 'beforeInsert', 'afterInsert', 'afterInsert', 'beforeTransactionCommit', 'afterTransactionCommit'], $subscriber->names());
        $subscriber->events = [];
        $this->users->save($this->user('Bob'), ['transaction' => false]);
        $this->assertSame(['beforeInsert', 'afterInsert'], $subscriber->names());
        $this->assertSame(3, $this->users->count());
    }
}
