<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use DateTimeImmutable;
use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\InsertResult;
use Nesto\Orm\LockNotSupportedOnGivenDriverException;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\NoVersionOrUpdateDateColumnException;
use Nesto\Orm\OptimisticLockCanNotBeUsedException;
use Nesto\Orm\OptimisticLockVersionMismatchException;
use Nesto\Orm\OrmException;
use Nesto\Orm\PessimisticLockTransactionRequiredException;
use Nesto\Orm\Repository;
use Nesto\Tests\Orm\Fixtures\Events\Account;
use Nesto\Tests\Orm\Fixtures\Events\AccountEntry;
use Nesto\Tests\Orm\Fixtures\Events\AccountRepository;
use Nesto\Tests\Orm\Fixtures\Tag;
use PHPUnit\Framework\TestCase;

/** #[VersionColumn], optimistic / pessimistic locks (SQLite side), upsert, preload, aggregates, custom repositories. */
final class LockingAndRepositoryTest extends TestCase
{
    private DataSource $ds;
    private Repository $accounts;

    protected function setUp(): void
    {
        MetadataRegistry::clear();
        $this->ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [Account::class, AccountEntry::class, Tag::class], 'synchronize' => true]);
        $this->ds->initialize();
        $this->accounts = $this->ds->getRepository(Account::class);
    }

    private function save(string $owner, int $balance = 0): Account
    {
        return $this->accounts->save($this->accounts->create(['owner' => $owner, 'email' => strtolower($owner) . '@x.io', 'balance' => $balance]));
    }

    public function testVersionColumnStartsAtOneAndCountsEveryWrite(): void
    {
        $ada = $this->save('Ada');
        $this->assertSame(1, $ada->version);
        $this->accounts->save($ada);
        $this->assertSame(1, $ada->version, 'an unchanged save writes nothing');
        $ada->balance = 10;
        $this->accounts->save($ada);
        $this->assertSame(2, $ada->version);
        $this->accounts->update($ada->id, ['balance' => 20]);       // query-builder update bumps it too
        $this->assertSame(3, $this->accounts->findOneByOrFail(['id' => $ada->id])->version);
        $this->accounts->increment($ada->id, 'balance', 5);
        $this->assertSame([25, 4], [($fresh = $this->accounts->findOneByOrFail(['id' => $ada->id]))->balance, $fresh->version]);
        $this->accounts->insert(['owner' => 'Bob', 'email' => 'bob@x.io']);
        $this->assertSame(1, $this->accounts->findOneByOrFail(['owner' => 'Bob'])->version);
        $this->assertSame('version', MetadataRegistry::get(Account::class)->versionColumn()->name);
    }

    public function testStaleEntitySaveFollowsTypeOrmAndBumpsTheStoredVersion(): void
    {
        $ada = $this->save('Ada');
        $copy = $this->accounts->findOneByOrFail(['id' => $ada->id]);
        $copy->balance = 5;
        $this->accounts->save($copy);                 // version 2
        $ada->balance = 7;
        $this->accounts->save($ada);                  // TypeORM does not check versions on save: last write wins
        $this->assertSame(3, $ada->version, 'the stored version is read back, not computed from the stale copy');
    }

    public function testOptimisticLock(): void
    {
        $ada = $this->save('Ada');
        $found = $this->accounts->findOne(['where' => ['id' => $ada->id], 'lock' => ['mode' => 'optimistic', 'version' => 1]]);
        $this->assertSame($ada->id, $found->id);
        $this->assertNull($this->accounts->findOne(['where' => ['id' => 99], 'lock' => ['mode' => 'optimistic', 'version' => 1]]));

        try {
            $this->accounts->findOne(['where' => ['id' => $ada->id], 'lock' => ['mode' => 'optimistic', 'version' => 4]]);
            $this->fail('expected a version mismatch');
        } catch (OptimisticLockVersionMismatchException $e) {
            $this->assertSame(409, $e->getStatus());
            $this->assertSame(4, $e->expectedVersion);
            $this->assertSame(1, $e->actualVersion);
            $this->assertStringContainsString('version 4 was expected, but is actually 1', $e->getMessage());
        }

        // by update date
        $this->accounts->findOneByOrFail(['id' => $ada->id]);
        $stamp = $this->accounts->findOneByOrFail(['id' => $ada->id])->updatedAt;
        $this->assertNotNull($this->accounts->createQueryBuilder('a')->where(['id' => $ada->id])->setLock('optimistic', $stamp)->getOne());
        $this->expectException(OptimisticLockVersionMismatchException::class);
        $this->accounts->createQueryBuilder('a')->where(['id' => $ada->id])->setLock('optimistic', new DateTimeImmutable('2000-01-01'))->getOne();
    }

    public function testOptimisticLockMisuse(): void
    {
        $this->save('Ada');
        try {
            $this->accounts->find(['lock' => ['mode' => 'optimistic', 'version' => 1]]);
            $this->fail('optimistic locks only work with findOne');
        } catch (OptimisticLockCanNotBeUsedException) {
        }
        $tags = $this->ds->getRepository(Tag::class);
        $tags->save($tags->create(['name' => 'php']));
        try {
            $tags->findOne(['where' => ['name' => 'php'], 'lock' => ['mode' => 'optimistic', 'version' => 1]]);
            $this->fail('Tag has no version column');
        } catch (NoVersionOrUpdateDateColumnException) {
        }
        $this->expectExceptionMessage('needs a version');
        $this->accounts->findOne(['lock' => ['mode' => 'optimistic']]);
    }

    public function testPessimisticLocksNeedATransactionAndAreNotSupportedOnSqlite(): void
    {
        $this->save('Ada');
        try {
            $this->accounts->find(['lock' => ['mode' => 'pessimistic_write']]);
            $this->fail('expected PessimisticLockTransactionRequiredException');
        } catch (PessimisticLockTransactionRequiredException) {
        }
        $this->ds->transaction(function (EntityManager $tx): void {
            try {
                $tx->find(Account::class, ['lock' => ['mode' => 'pessimistic_write']]);
                $this->fail('SQLite has no row locks');
            } catch (LockNotSupportedOnGivenDriverException) {
            }
            // dirty_read adds no SQL and needs no transaction support
            $this->assertCount(1, $tx->find(Account::class, ['lock' => ['mode' => 'dirty_read']]));
        });
        $this->assertCount(1, $this->accounts->find(['lock' => ['mode' => 'dirty_read']]));
        $this->assertSame(1, $this->accounts->count(['lock' => ['mode' => 'pessimistic_write']]), 'counts ignore the lock');
    }

    public function testLockOptionValidation(): void
    {
        foreach ([['mode' => 'exclusive'], ['mode' => 'pessimistic_write', 'onLocked' => 'wait'], ['mode' => 'pessimistic_write', 'tables' => []], ['mode' => 'pessimistic_write', 'timeout' => 1], 'pessimistic_write'] as $lock) {
            try {
                $this->accounts->find(['lock' => $lock]);
                $this->fail('accepted ' . json_encode($lock));
            } catch (OrmException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUpsertInsertsAndUpdatesByConflictPaths(): void
    {
        $ada = $this->save('Ada', 10);
        $result = $this->accounts->upsert([
            ['owner' => 'Ada L', 'email' => 'ada@x.io', 'balance' => 15],
            ['owner' => 'Bob', 'email' => 'bob@x.io', 'balance' => 1],
        ], ['email']);

        $this->assertInstanceOf(InsertResult::class, $result);
        $bob = $this->accounts->findOneByOrFail(['owner' => 'Bob']);
        $this->assertSame([['id' => $ada->id], ['id' => $bob->id]], $result->identifiers); // ids may have gaps (auto-increment)
        $this->assertSame(2, $result->generatedMaps[0]['version'], 'the updated row has its version bumped');
        $this->assertSame(1, $result->generatedMaps[1]['version']);
        $rows = $this->ds->getConnection()->fetchAll('SELECT owner, balance, version FROM ev_accounts ORDER BY id');
        $this->assertSame([['owner' => 'Ada L', 'balance' => 15, 'version' => 2], ['owner' => 'Bob', 'balance' => 1, 'version' => 1]], $rows);
        $this->assertSame($ada->createdAt->format('Y-m-d H:i:s'), $this->accounts->findOneByOrFail(['id' => $ada->id])->createdAt->format('Y-m-d H:i:s'), 'the creation date is kept');
    }

    public function testUpsertEntitiesGetTheirKeysAndCanSkipUnchangedRows(): void
    {
        $this->save('Ada', 10);
        $entity = $this->accounts->create(['owner' => 'Ada', 'email' => 'ada@x.io', 'balance' => 10]);
        $result = $this->accounts->upsert($entity, ['conflictPaths' => ['email' => true], 'skipUpdateIfNoValuesChanged' => true]);
        $this->assertSame(1, $entity->id);
        $this->assertSame(1, $entity->version, 'nothing changed: the row was not updated');
        $this->assertSame(['id' => 1], $result->identifiers[0]);

        $entity->balance = 11;
        $this->accounts->save($entity); // the upsert recorded a snapshot: this is an UPDATE
        $this->assertSame(2, $entity->version);
        $this->assertSame(1, $this->accounts->count());

        $this->expectExceptionMessage('needs the conflict paths');
        $this->accounts->upsert(['owner' => 'X', 'email' => 'x@x.io'], []);
    }

    public function testPreload(): void
    {
        $ada = $this->save('Ada', 10);
        $preloaded = $this->accounts->preload(['id' => $ada->id, 'balance' => 99]);
        $this->assertSame('Ada', $preloaded->owner);
        $this->assertSame(99, $preloaded->balance);
        $this->accounts->save($preloaded);
        $this->assertSame(99, $this->accounts->findOneByOrFail(['id' => $ada->id])->balance);
        $this->assertNull($this->accounts->preload(['id' => 42]));
        $this->assertNull($this->accounts->preload(['owner' => 'no id']));
        $this->assertSame([], $this->accounts->preload(['id' => $ada->id, 'entries' => []])->entries);
    }

    public function testAggregates(): void
    {
        $this->save('Ada', 10);
        $this->save('Bob', 30);
        $cyd = $this->save('Cyd', 5);
        $this->accounts->softRemove($cyd);

        $this->assertSame(40.0, $this->accounts->sum('balance'));
        $this->assertSame(20.0, $this->accounts->average('balance'));
        $this->assertSame(10, $this->accounts->minimum('balance'));
        $this->assertSame(30, $this->accounts->maximum('balance', ['owner' => ['In', ['Ada', 'Bob']]]));
        $this->assertNull($this->accounts->sum('balance', ['owner' => 'nobody']));
        $this->assertInstanceOf(DateTimeImmutable::class, $this->accounts->maximum('createdAt'));
        $this->assertSame(30.0, $this->ds->manager->sum(Account::class, 'balance', ['owner' => 'Bob']));
        [$found, $count] = $this->accounts->findAndCountBy(['owner' => 'Ada']);
        $this->assertSame([1, 1], [count($found), $count]);
        $this->assertTrue($this->accounts->hasId($found[0]));
        $this->assertSame(1, $this->accounts->getId($found[0]));
        $this->expectExceptionMessage('has no column "nope"');
        $this->accounts->sum('nope');
    }

    public function testCustomRepository(): void
    {
        $repository = new AccountRepository($this->ds);
        $this->assertSame(Account::class, $repository->entity);
        $this->save('Ada', 10);
        $this->save('Bob', 30);
        $this->assertSame('Bob', $repository->richest()->owner);

        $this->ds->transaction(function (EntityManager $tx) use ($repository): void {
            $bound = $tx->withRepository($repository);
            $this->assertInstanceOf(AccountRepository::class, $bound);
            $this->assertSame('Bob', $bound->richest()->owner);
        });

        $this->expectExceptionMessage('without an entity');
        new class ($this->ds) extends Repository {};
    }
}
