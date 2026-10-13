<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Core\NestoFactory;
use Nesto\Http\Request;
use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\OrmModule;
use Nesto\Tests\Orm\Fixtures\Bank\AccountSubscriber;
use Nesto\Tests\Orm\Fixtures\Bank\AuditTrail;
use Nesto\Tests\Orm\Fixtures\Bank\BankAppModule;
use Nesto\Tests\Orm\Fixtures\Bank\BankService;
use Nesto\Tests\Orm\Fixtures\Bank\NestStyleSubscriber;
use Nesto\Tests\Orm\Fixtures\Bank\OptionSubscriber;
use Nesto\Tests\Orm\Fixtures\Events\Account;
use Nesto\Tests\Orm\Fixtures\Events\AccountRepository;
use Nesto\Tests\Orm\Fixtures\Tag;
use PHPUnit\Framework\TestCase;

/** Subscribers through DI, custom repositories, named data sources, #[InjectDataSource] / #[InjectEntityManager], #[Transactional] options. */
final class OrmModuleExtrasTest extends TestCase
{
    protected function setUp(): void
    {
        MetadataRegistry::clear();
    }

    public function testSubscribersCustomRepositoriesAndNamedDataSources(): void
    {
        $app = NestoFactory::create(BankAppModule::class);
        $app->init();
        $bank = $app->get(BankService::class);
        $ds = $app->get(DataSource::class);

        // injection helpers
        $this->assertSame($ds, $bank->dataSource);
        $this->assertSame($ds->manager, $bank->manager);
        $this->assertNotSame($ds, $bank->analytics);
        $this->assertSame($bank->analytics, $app->get(OrmModule::getDataSourceToken('analytics')));
        $this->assertSame($bank->analytics->manager, $bank->analyticsManager);
        $this->assertSame($app->get(EntityManager::class), $app->get(OrmModule::getEntityManagerToken('default')));
        $this->assertSame(Tag::class, $bank->tags->entity);
        $this->assertTrue($bank->analytics->hasEntity(Tag::class));
        $this->assertFalse($ds->hasEntity(Tag::class));

        // the custom repository is injected by class and by the entity's repository token
        $this->assertInstanceOf(AccountRepository::class, $bank->accounts);
        $this->assertSame($bank->accounts, $bank->viaToken);
        $this->assertSame($bank->accounts, $app->get(OrmModule::repositoryToken(Account::class)));

        // subscribers: #[EventSubscriber] provider (DI), option class (DI), self-registering provider
        $subscribers = array_map(fn (object $s) => $s::class, $ds->subscribers);
        sort($subscribers);
        $this->assertSame([AccountSubscriber::class, NestStyleSubscriber::class, OptionSubscriber::class], $subscribers);
        $this->assertSame([], $bank->analytics->subscribers);
        $option = array_values(array_filter($ds->subscribers, fn (object $s) => $s instanceof OptionSubscriber))[0];
        $this->assertSame($ds, $option->dataSource);

        $bank->accounts->save($bank->accounts->create(['owner' => 'Bob', 'email' => 'bob@x.io']));
        $this->assertSame(['inserted Bob'], $app->get(AuditTrail::class)->lines);
        $this->assertSame(1, $app->get(NestStyleSubscriber::class)->inserts);
        $this->assertSame(1, $option->commits);
        $this->assertSame('Bob', $bank->accounts->richest()->owner);
    }

    public function testTransactionalWithIsolationLevelAndNamedDataSource(): void
    {
        $app = NestoFactory::create(BankAppModule::class);
        $response = $app->handle(Request::create('POST', '/bank/open'));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(['depth' => 1, 'readUncommitted' => 1, 'runner' => true], json_decode((string) $response->getBody(), true));
        $ds = $app->get(DataSource::class);
        $this->assertFalse($ds->getConnection()->inTransaction());
        $this->assertFalse($ds->currentQueryRunner()->isTransactionActive(), 'the runner was released with the request');

        $response = $app->handle(Request::create('POST', '/bank/tag'));
        $this->assertSame(['analytics' => 1, 'default' => 0], json_decode((string) $response->getBody(), true));
        $this->assertSame(1, $app->get(BankService::class)->tags->count());
    }
}
