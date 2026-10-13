<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\DataSource;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\Repository;
use Nesto\Tests\Orm\Fixtures\Events\Account;
use Nesto\Tests\Orm\Fixtures\Events\AccountEntry;
use Nesto\Tests\Orm\Fixtures\Events\EventLog;
use PHPUnit\Framework\TestCase;

/** #[BeforeInsert] … #[AfterLoad] entity listeners (TypeORM semantics). */
final class EntityListenersTest extends TestCase
{
    private DataSource $ds;
    private Repository $accounts;

    protected function setUp(): void
    {
        MetadataRegistry::clear();
        EventLog::take();
        $this->ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [Account::class, AccountEntry::class], 'synchronize' => true]);
        $this->ds->initialize();
        $this->accounts = $this->ds->getRepository(Account::class);
    }

    private function account(string $owner, array $entries = []): Account
    {
        $account = $this->accounts->create(['owner' => $owner, 'email' => strtolower($owner) . '@x.io']);
        if ($entries !== []) {
            $account->entries = array_map(fn (int $amount) => $this->ds->getRepository(AccountEntry::class)->create(['amount' => $amount]), $entries);
        }

        return $account;
    }

    public function testInsertListenersRunAroundTheInsertAndTheirChangesAreWritten(): void
    {
        $ada = $this->accounts->save($this->account('Ada', [10, 20]));

        $this->assertSame([
            'parent beforeInsert', 'beforeInsert Ada',
            'beforeInsert entry 10', 'beforeInsert entry 20',
            // after-events once every row is written, in write order (TypeORM: after all operations)
            'afterInsert Ada #1', 'afterInsert entry 10', 'afterInsert entry 20',
        ], EventLog::take());
        $this->assertSame('ada', $ada->slug);
        $this->assertSame('ada', $this->ds->getConnection()->fetchColumn('SELECT slug FROM ev_accounts'));
        $this->assertSame(1, $ada->version);
    }

    public function testBeforeUpdateRunsOnlyWhenColumnsChangedAndItsChangesAreWritten(): void
    {
        $ada = $this->accounts->save($this->account('Ada'));
        EventLog::take();

        $this->accounts->save($ada); // nothing changed: no update, no listener (TypeORM)
        $this->assertSame([], EventLog::take());

        $ada->owner = 'Ada Lovelace';
        $this->accounts->save($ada);
        $this->assertSame(['parent beforeUpdate', 'beforeUpdate Ada Lovelace', 'afterUpdate Ada Lovelace v2'], EventLog::take());
        $row = $this->ds->getConnection()->fetchOne('SELECT owner, slug, version FROM ev_accounts');
        $this->assertSame(['owner' => 'Ada Lovelace', 'slug' => 'ada lovelace', 'version' => 2], $row);
    }

    public function testRemoveSoftRemoveAndRecoverListeners(): void
    {
        $ada = $this->accounts->save($this->account('Ada'));
        $bob = $this->accounts->save($this->account('Bob'));
        EventLog::take();

        $this->accounts->softRemove($ada);
        $this->assertSame(['beforeSoftRemove Ada', 'afterSoftRemove Ada'], EventLog::take());
        $this->assertSame(2, $ada->version, 'softRemove bumps the version like TypeORM');

        $this->accounts->recover($ada);
        $this->assertSame(['beforeRecover Ada', 'afterRecover Ada'], EventLog::take());

        $this->accounts->remove($bob);
        $this->assertSame(['beforeRemove Bob', 'afterRemove Bob'], EventLog::take());
    }

    public function testListenersCanBeDisabledPerCall(): void
    {
        $ada = $this->account('Ada');
        $this->accounts->save($ada, ['listeners' => false]);
        $this->assertSame([], EventLog::take());
        $this->assertNull($ada->slug);
    }

    public function testAfterLoadRunsAfterRelationsAreLoadedChildrenFirst(): void
    {
        $this->accounts->save([$this->account('Ada', [1, 2]), $this->account('Bob')]);
        EventLog::take();

        $found = $this->accounts->find(['relations' => ['entries'], 'order' => ['id' => 'ASC']]);
        $this->assertSame(['afterLoad entry 1', 'afterLoad entry 2', 'afterLoad Ada', 'afterLoad Bob'], EventLog::take());
        $this->assertSame('Ada (2 entries)', $found[0]->label);
        $this->assertSame('Bob (0 entries)', $found[1]->label);

        foreach (['join', 'query'] as $strategy) {
            $one = $this->accounts->findOneOrFail(['where' => ['owner' => 'Ada'], 'relations' => ['entries'], 'relationLoadStrategy' => $strategy]);
            $this->assertSame('Ada (2 entries)', $one->label, $strategy);
            $this->assertSame(['afterLoad entry 1', 'afterLoad entry 2', 'afterLoad Ada'], EventLog::take(), $strategy);
        }
    }

    public function testAfterLoadRunsForQueryBuilderResultsAndJoinedRelations(): void
    {
        $this->accounts->save($this->account('Ada', [5]));
        EventLog::take();

        $accounts = $this->accounts->createQueryBuilder('a')->leftJoinAndSelect('a.entries', 'en')->getMany();
        $this->assertSame(['afterLoad entry 5', 'afterLoad Ada'], EventLog::take());
        $this->assertSame('Ada (1 entries)', $accounts[0]->label);

        $this->accounts->createQueryBuilder('a')->getOne();
        $this->assertSame(['afterLoad Ada'], EventLog::take());

        $entries = $this->ds->getRepository(AccountEntry::class)->find(['relations' => ['account']]);
        $this->assertSame(['afterLoad Ada', 'afterLoad entry 5'], EventLog::take());
        $this->assertSame('Ada (entries not loaded)', $entries[0]->account->label);
    }

    public function testListenerMethodsMustNotRequireParameters(): void
    {
        $this->expectExceptionMessage('must not require parameters');
        \Nesto\Orm\Events\EntityListeners::for(BadListener::class, 'beforeInsert');
    }
}

final class BadListener
{
    #[\Nesto\Orm\Attributes\BeforeInsert]
    public function needsArgument(string $x): void {}
}
