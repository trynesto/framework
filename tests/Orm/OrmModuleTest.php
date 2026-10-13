<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Common\Attributes\Module;
use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;
use Nesto\Core\NestoFactory;
use Nesto\Http\Request;
use Nesto\Orm\Connection;
use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\OrmModule;
use Nesto\Orm\Repository;
use Nesto\Tests\Orm\Fixtures\AppModule;
use Nesto\Tests\Orm\Fixtures\Cat;
use Nesto\Tests\Orm\Fixtures\CatsModule;
use Nesto\Tests\Orm\Fixtures\CatsService;
use PHPUnit\Framework\TestCase;

final class OrmModuleTest extends TestCase
{
    protected function setUp(): void
    {
        MetadataRegistry::clear();
    }

    public function testFullApplicationFlow(): void
    {
        $app = NestoFactory::create(AppModule::class);
        $app->init();

        $this->assertInstanceOf(DataSource::class, $app->get(DataSource::class));
        $this->assertInstanceOf(EntityManager::class, $app->get(EntityManager::class));
        $this->assertInstanceOf(Connection::class, $app->get(Connection::class));
        $repo = $app->get(OrmModule::repositoryToken(Cat::class));
        $this->assertInstanceOf(Repository::class, $repo);
        $this->assertSame(Cat::class, $repo->entity);
        $this->assertSame($repo, $app->get(CatsService::class)->cats);

        $created = $app->handle(Request::create('POST', '/cats', body: ['name' => 'Tom', 'age' => 3]));
        $this->assertSame(201, $created->getStatusCode());
        $payload = json_decode((string) $created->getBody(), true);
        $this->assertSame(1, $payload['id']);
        $this->assertSame('Tom', $payload['name']);
        $this->assertSame(3, $payload['age']);
        $this->assertIsString($payload['createdAt']);

        $app->handle(Request::create('POST', '/cats', body: ['name' => 'Felix']));

        $list = $app->handle(Request::create('GET', '/cats'));
        $this->assertSame(200, $list->getStatusCode());
        $cats = json_decode((string) $list->getBody(), true);
        $this->assertSame(['Felix', 'Tom'], array_column($cats, 'name'));

        $one = $app->handle(Request::create('GET', '/cats/2'));
        $this->assertSame('Felix', json_decode((string) $one->getBody(), true)['name']);

        $missing = $app->handle(Request::create('GET', '/cats/99'));
        $this->assertSame(404, $missing->getStatusCode());
        $this->assertSame('Cat not found matching {"id":99}.', json_decode((string) $missing->getBody(), true)['message']);

        // #[Transactional] rolls back the insert made before the exception
        \Nesto\Logger\Logger::toStream(fopen('php://memory', 'w+b')); // the 500 is logged
        $failed = $app->handle(Request::create('POST', '/cats/failing', body: ['name' => 'Ghost']));
        \Nesto\Logger\Logger::toStream(null);
        $this->assertSame(500, $failed->getStatusCode());
        $this->assertSame(2, $repo->count());
        $this->assertFalse($app->get(Connection::class)->inTransaction());
    }

    public function testForRootAndForFeatureAtRuntime(): void
    {
        $root = new DynamicModule(
            module: RootModule::class,
            imports: [
                OrmModule::forRoot(['type' => 'sqlite', 'database' => ':memory:', 'synchronize' => true]),
                CatsModule::class,
            ],
        );
        $app = NestoFactory::create($root);
        $app->init();
        $ds = $app->get(DataSource::class);
        $this->assertTrue($ds->hasEntity(Cat::class)); // auto-loaded by forFeature
        $this->assertTrue($ds->schema()->hasTable('cats'));
        $app->get(CatsService::class)->create(['name' => 'Rt']);
        $this->assertSame(1, $app->get(OrmModule::repositoryToken(Cat::class))->count());
    }

    public function testForRootAsyncWithInjectedConfig(): void
    {
        $config = new DynamicModule(
            module: DbConfigModule::class,
            providers: [(new Provider('DB_CONFIG'))->useValue(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [Cat::class], 'synchronize' => true])],
            exports: ['DB_CONFIG'],
        );
        $root = new DynamicModule(
            module: RootModule::class,
            imports: [
                OrmModule::forRootAsync(['imports' => [$config], 'useFactory' => fn (array $config) => $config, 'inject' => ['DB_CONFIG']]),
                OrmModule::forFeature([Cat::class]),
            ],
        );
        $app = NestoFactory::create($root);
        $app->init();
        $this->assertTrue($app->get(DataSource::class)->schema()->hasTable('cats'));
        $this->assertInstanceOf(Repository::class, $app->get(OrmModule::repositoryToken(Cat::class)));
    }
}

#[Module]
final class RootModule {}

#[Module]
final class DbConfigModule {}
