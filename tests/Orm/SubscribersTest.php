<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\Events\AfterQueryEvent;
use Nesto\Orm\Events\BeforeQueryEvent;
use Nesto\Orm\Events\EntitySubscriberInterface;
use Nesto\Orm\Events\InsertEvent;
use Nesto\Orm\Events\LoadEvent;
use Nesto\Orm\Events\RemoveEvent;
use Nesto\Orm\Events\UpdateEvent;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\QueryException;
use Nesto\Orm\Repository;
use Nesto\Tests\Orm\Fixtures\Events\Account;
use Nesto\Tests\Orm\Fixtures\Events\AccountEntry;
use Nesto\Tests\Orm\Fixtures\Events\EventLog;
use Nesto\Tests\Orm\Fixtures\Events\RecordingSubscriber;
use PHPUnit\Framework\TestCase;

/** EntitySubscriberInterface (TypeORM's subscribers): entity, transaction and query events. */
final class SubscribersTest extends TestCase
{
    private DataSource $ds;
    private Repository $accounts;
    private RecordingSubscriber $subscriber;

    protected function setUp(): void
    {
        MetadataRegistry::clear();
        EventLog::take();
        $this->subscriber = new RecordingSubscriber;
        $this->ds = new DataSource([
            'type' => 'sqlite', 'database' => ':memory:', 'synchronize' => true,
            'entities' => [Account::class, AccountEntry::class],
            'subscribers' => [$this->subscriber],
        ]);
        $this->ds->initialize();
        $this->accounts = $this->ds->getRepository(Account::class);
    }

    private function newAccount(string $owner): Account
    {
        return $this->accounts->create(['owner' => $owner, 'email' => strtolower($owner) . '@x.io']);
    }

    public function testInsertEventsCarryTheEntityAndRunAfterTheEntityListeners(): void
    {
        $ada = $this->accounts->save($this->newAccount('Ada'));

        $this->assertSame(['parent beforeInsert', 'beforeInsert Ada', 'subscriber beforeInsert', 'afterInsert Ada #1'], EventLog::take());
        [$before] = $this->subscriber->of('beforeInsert');
        $this->assertInstanceOf(InsertEvent::class, $before);
        $this->assertSame($ada, $before->entity);
        $this->assertSame(Account::class, $before->metadata->class);
        $this->assertSame($this->ds, $before->connection);
        // save() ran on a QueryRunner of its own (TypeORM): the event's manager is that runner's
        $this->assertSame($before->queryRunner->manager, $before->manager);
        $this->assertTrue($before->queryRunner->isReleased(), 'released once save() returned');
        $this->assertSame($this->ds->manager, $this->ds->currentQueryRunner()->manager);
        $this->assertCount(1, $this->subscriber->of('afterInsert'));
        // save() opened its own transaction around the insert
        $this->assertSame(
            ['beforeTransactionStart', 'afterTransactionStart', 'beforeInsert', 'afterInsert', 'beforeTransactionCommit', 'afterTransactionCommit'],
            $this->subscriber->names(),
        );
    }

    public function testListenToFiltersEntities(): void
    {
        $all = new RecordingSubscriber(null);
        $this->ds->subscribers[] = $all; // the @nestjs/typeorm pattern
        $account = $this->newAccount('Ada');
        $account->entries = [$this->ds->getRepository(AccountEntry::class)->create(['amount' => 3])];
        $this->accounts->save($account);

        $this->assertCount(1, $this->subscriber->of('beforeInsert'));
        $this->assertSame([Account::class, AccountEntry::class], array_map(fn (InsertEvent $e) => $e->entity::class, $all->of('beforeInsert')));
    }

    public function testUpdateEventReportsChangedColumnsAndTheDatabaseEntity(): void
    {
        $ada = $this->accounts->save($this->newAccount('Ada'));
        $this->subscriber->events = [];
        $ada->balance = 50;
        $ada->owner = 'Ada L';
        $this->accounts->save($ada);

        [$event] = $this->subscriber->of('beforeUpdate');
        $this->assertInstanceOf(UpdateEvent::class, $event);
        $this->assertSame($ada, $event->entity);
        $this->assertSame(['owner', 'balance'], array_map(fn ($c) => $c->name, $event->updatedColumns));
        $this->assertSame([], $event->updatedRelations);
        $this->assertInstanceOf(Account::class, $event->databaseEntity);
        $this->assertNotSame($ada, $event->databaseEntity);
        $this->assertSame(0, $event->databaseEntity->balance);
        $this->assertSame('Ada', $event->databaseEntity->owner);
        // the entity listener changed the slug in beforeUpdate: written, and reported after the update
        [$after] = $this->subscriber->of('afterUpdate');
        $this->assertSame(['owner', 'balance', 'slug'], array_map(fn ($c) => $c->name, $after->updatedColumns));
    }

    public function testUpdateEventReportsChangedRelations(): void
    {
        $entries = $this->ds->getRepository(AccountEntry::class);
        $ada = $this->accounts->save($this->newAccount('Ada'));
        $bob = $this->accounts->save($this->newAccount('Bob'));
        $entry = $entries->create(['amount' => 1]);
        $entry->account = $ada;
        $entries->save($entry);
        $all = new RecordingSubscriber(AccountEntry::class);
        $this->ds->addSubscriber($all)->addSubscriber($all);

        $entry->account = $bob;
        $entries->save($entry);
        [$event] = $all->of('beforeUpdate');
        $this->assertSame(['account_id'], array_map(fn ($c) => $c->name, $event->updatedColumns));
        $this->assertSame(['account'], array_map(fn ($r) => $r->property, $event->updatedRelations));
        $this->assertCount(1, $all->of('afterUpdate'), 'a subscriber registered twice is called once');
    }

    public function testRemoveSoftRemoveAndRecoverEvents(): void
    {
        $ada = $this->accounts->save($this->newAccount('Ada'));
        $this->accounts->softRemove($ada);
        $this->accounts->recover($ada);
        $id = $ada->id;
        $this->accounts->remove($ada);

        $this->assertSame(
            ['beforeSoftRemove', 'afterSoftRemove', 'beforeRecover', 'afterRecover', 'beforeRemove', 'afterRemove'],
            array_values(array_filter($this->subscriber->names(), fn (string $n) => ! str_contains($n, 'Transaction') && ! str_contains($n, 'Insert'))),
        );
        [$remove] = $this->subscriber->of('beforeRemove');
        $this->assertInstanceOf(RemoveEvent::class, $remove);
        $this->assertSame($id, $remove->entityId);
        $this->assertSame($ada, $remove->entity);
        $this->assertSame('Ada', $remove->databaseEntity->owner);
    }

    public function testAfterLoadReceivesEntityAndEvent(): void
    {
        $this->accounts->save($this->newAccount('Ada'));
        $this->subscriber->events = [];
        EventLog::take();
        $ada = $this->accounts->findOneByOrFail(['owner' => 'Ada']);

        [$event] = $this->subscriber->of('afterLoad');
        $this->assertInstanceOf(LoadEvent::class, $event);
        $this->assertSame($ada, $event->entity);
        $this->assertSame(['afterLoad Ada', 'subscriber afterLoad ' . Account::class], EventLog::take());
    }

    public function testQueryBuilderAndRepositoryWritesFireEventsWithTheirValues(): void
    {
        $this->accounts->insert(['owner' => 'Ada', 'email' => 'ada@x.io']);
        $this->accounts->update(['owner' => 'Ada'], ['balance' => 5]);
        $this->accounts->delete(['owner' => 'Ada']);
        $this->ds->createQueryBuilder()->insert()->into(Account::class)->values([['owner' => 'B', 'email' => 'b@x.io'], ['owner' => 'C', 'email' => 'c@x.io']])->execute();
        $this->ds->createQueryBuilder()->update(Account::class)->set(['balance' => 1])->callListeners(false)->execute();

        $names = array_values(array_filter($this->subscriber->names(), fn (string $n) => ! str_contains($n, 'Transaction')));
        $this->assertSame(['beforeInsert', 'afterInsert', 'beforeUpdate', 'afterUpdate', 'beforeRemove', 'afterRemove', 'beforeInsert', 'beforeInsert', 'afterInsert', 'afterInsert'], $names);
        $this->assertSame(['owner' => 'Ada', 'email' => 'ada@x.io'], $this->subscriber->of('beforeInsert')[0]->entity);
        $this->assertSame(['balance' => 5], $this->subscriber->of('beforeUpdate')[0]->entity);
        $this->assertNull($this->subscriber->of('beforeRemove')[0]->entity);
    }

    public function testTransactionEventsReportTheTransactionalQueryRunner(): void
    {
        $this->ds->transaction(function (EntityManager $tx): void {
            $tx->save($this->newAccount('Ada'));
            try {
                $tx->transaction(function (EntityManager $inner): never {
                    throw new \RuntimeException('inner');
                });
            } catch (\RuntimeException) {
            }
        });
        $this->assertSame([
            'beforeTransactionStart', 'afterTransactionStart',
            'beforeInsert', 'afterInsert',                                // no transaction of its own inside one
            'beforeTransactionStart', 'afterTransactionStart',           // savepoint
            'beforeTransactionRollback', 'afterTransactionRollback',
            'beforeTransactionCommit', 'afterTransactionCommit',
        ], $this->subscriber->names());
        $start = $this->subscriber->of('afterTransactionStart')[0];
        $insert = $this->subscriber->of('beforeInsert')[0];
        $this->assertSame($start->queryRunner, $insert->queryRunner);
        $this->assertSame($start->queryRunner->manager, $insert->manager);
        $this->assertNotSame($this->ds->manager, $insert->manager);
        $this->assertTrue($start->queryRunner->isReleased());
    }

    public function testQueryEvents(): void
    {
        $this->subscriber->queries = true;
        $this->accounts->count();
        try {
            $this->ds->manager->query('SELECT nope FROM nowhere');
        } catch (QueryException) {
        }
        $before = $this->subscriber->of('beforeQuery');
        $after = $this->subscriber->of('afterQuery');
        $this->assertCount(2, $before);
        $this->assertInstanceOf(BeforeQueryEvent::class, $before[0]);
        $this->assertStringContainsString('COUNT', $before[0]->query);
        $this->assertInstanceOf(AfterQueryEvent::class, $after[0]);
        $this->assertTrue($after[0]->success);
        $this->assertFalse($after[1]->success);
        $this->assertInstanceOf(QueryException::class, $after[1]->error);
        $this->assertGreaterThanOrEqual(0.0, $after[0]->executionTime);
    }

    public function testASubscriberRunningQueriesFromAQueryEventDoesNotRecurse(): void
    {
        $counter = new class implements EntitySubscriberInterface {
            public int $calls = 0;
            public ?DataSource $ds = null;

            public function afterQuery(AfterQueryEvent $event): void
            {
                $this->calls++;
                $event->manager->query('SELECT 1');
            }
        };
        $this->ds->addSubscriber($counter);
        $this->ds->manager->query('SELECT 2');
        $this->assertSame(1, $counter->calls);
    }

    public function testSubscriberClassesFromOptionsAreInstantiated(): void
    {
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'subscribers' => [RecordingSubscriber::class]]);
        $this->assertInstanceOf(RecordingSubscriber::class, $ds->subscribers[0]);
    }
}
