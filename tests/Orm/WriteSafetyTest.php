<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\DataSource;
use Nesto\Orm\Events\EntitySubscriberInterface;
use Nesto\Orm\Events\InsertEvent;
use Nesto\Orm\Logging\NullLogger;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\OrmModule;
use Nesto\Orm\Types\TypeRegistry;
use Nesto\Tests\Orm\Fixtures\Columns\MoneyType;
use Nesto\Tests\Orm\Fixtures\Post;
use Nesto\Tests\Orm\Fixtures\Profile;
use Nesto\Tests\Orm\Fixtures\Tag;
use Nesto\Tests\Orm\Fixtures\User;
use PHPUnit\Framework\TestCase;

/** Write-safety details beyond the driver scenarios (WriteSafetyScenarios): query counts, save `data`, OrmModule options. */
final class WriteSafetyTest extends TestCase
{
    /** @var list<string> */
    private array $sql = [];

    protected function setUp(): void
    {
        MetadataRegistry::clear();
    }

    private function dataSource(array $options = []): DataSource
    {
        return (new DataSource($options + [
            'type' => 'sqlite', 'database' => ':memory:', 'synchronize' => true,
            'entities' => [User::class, Post::class, Tag::class, Profile::class],
            'logging' => function (string $sql): void {
                $this->sql[] = $sql;
            },
        ]))->initialize();
    }

    public function testANeverLoadedEntityCostsOneSelectAndAnUpdateOfWhatItSets(): void
    {
        $ds = $this->dataSource();
        $users = $ds->getRepository(User::class);
        $ada = $users->save($users->create(['name' => 'Ada', 'email' => 'ada@x.io', 'age' => 36]));
        $this->sql = [];
        $users->save($users->create(['id' => $ada->id, 'name' => 'Ada L.']), ['transaction' => false]);
        $this->assertCount(2, $this->sql, implode("\n", $this->sql));
        $this->assertStringStartsWith('SELECT', $this->sql[0]);
        $this->assertMatchesRegularExpression('/^UPDATE "users" SET "name" = :v0, "updated_at" = :v1 WHERE/', $this->sql[1]);

        // nothing to write: the SELECT only
        $this->sql = [];
        $users->save($users->create(['id' => $ada->id, 'name' => 'Ada L.']), ['transaction' => false]);
        $this->assertCount(1, $this->sql, implode("\n", $this->sql));
    }

    public function testSaveDataReachesSubscribersThroughTheQueryRunner(): void
    {
        $subscriber = new class implements EntitySubscriberInterface {
            /** @var list<array<string, mixed>> */
            public array $seen = [];

            public function listenTo(): string
            {
                return Tag::class;
            }

            public function beforeInsert(InsertEvent $event): void
            {
                $this->seen[] = $event->queryRunner->data;
            }
        };
        $ds = $this->dataSource(['subscribers' => [$subscriber]]);
        $tags = $ds->getRepository(Tag::class);
        $tags->save($tags->create(['name' => 'a']), ['data' => ['user' => 7]]);
        $tags->save($tags->create(['name' => 'b']), ['data' => ['user' => 8], 'transaction' => false]);
        $tags->save($tags->create(['name' => 'c']));
        $this->assertSame([['user' => 7], ['user' => 8], []], $subscriber->seen);
        $this->assertSame([], $ds->currentQueryRunner()->data, 'restored afterwards');
    }

    public function testOrmModulePassesEveryDataSourceOption(): void
    {
        $module = (new OrmModule(
            type: 'sqlite',
            database: ':memory:',
            entities: [Tag::class],
            logger: new NullLogger,
            relationLoadStrategy: 'join',
            types: ['ws_money' => MoneyType::class],
        ))->toDynamicModule();
        $options = $module->providers[0]->useValue;
        $this->assertSame('join', $options['relationLoadStrategy']);
        $this->assertSame(['ws_money' => MoneyType::class], $options['types']);
        $this->assertInstanceOf(NullLogger::class, $options['logger']);

        $ds = new DataSource($options);
        $this->assertSame('join', $ds->getOptions()['relationLoadStrategy']);
        $this->assertNotNull(TypeRegistry::get('ws_money'));

        $forRoot = OrmModule::forRoot(['type' => 'sqlite', 'relationLoadStrategy' => 'join', 'types' => ['ws_money' => MoneyType::class]]);
        $this->assertSame('join', $forRoot->providers[0]->useValue['relationLoadStrategy']);
    }
}
