<?php

declare(strict_types=1);

namespace Nesto\Tests\Cli;

use Nesto\Cli\Application;
use Nesto\Cli\Commands\BuiltIn;
use Nesto\Cli\Output;
use Nesto\Cli\ProjectContext;
use Nesto\Common\Attributes\Inject;
use Nesto\Common\Attributes\Module;
use Nesto\Orm\Attributes\InjectRepository;
use Nesto\Orm\DataSource;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\OrmModule;
use Nesto\Orm\Repository;
use Nesto\Tests\Orm\Fixtures\AppModule;
use Nesto\Tests\Orm\Fixtures\Cat;
use Nesto\Tests\Orm\Fixtures\CatsService;
use PHPUnit\Framework\TestCase;

/** Feature-module providers (CatsService, the Cat repository of CatsModule) are not exported to the root module. */
final class SeedCatsSeeder
{
    /** @var list<string> names in the database after run() (the in-memory app is closed after the command) */
    public static array $seeded = [];

    #[Inject(CatsService::class)]
    public CatsService $viaProperty;

    public function __construct(
        #[InjectRepository(Cat::class)] private readonly Repository $cats,
        private readonly CatsService $service,
    ) {
    }

    public function run(DataSource $dataSource): void
    {
        $this->cats->save($this->cats->create(['name' => 'Tom', 'age' => 3]));
        $this->service->create(['name' => 'Kit', 'age' => 1]);
        $this->viaProperty->create(['name' => 'Ace', 'age' => 2]);
        $dataSource->getRepository(Cat::class)->save($dataSource->getRepository(Cat::class)->create(['name' => 'Zed', 'age' => 9]));
        self::$seeded = array_map(fn (Cat $c) => $c->name, $this->cats->find(['order' => ['name' => 'ASC']]));
    }
}

/** The entity has no OrmModule::forFeature() registration at all: the repository comes from the DataSource. */
final class SeedUnregisteredSeeder
{
    public static int $count = 0;

    public function __construct(#[InjectRepository(Cat::class)] private readonly Repository $cats)
    {
    }

    public function run(): void
    {
        $this->cats->save($this->cats->create(['name' => 'Solo', 'age' => 4]));
        self::$count = $this->cats->count();
    }
}

#[Module(imports: [new OrmModule(type: 'sqlite', database: ':memory:', synchronize: true, entities: [Cat::class])])]
final class SeedOrmOnlyModule
{
}

final class DbSeedCommandTest extends TestCase
{
    protected function setUp(): void
    {
        MetadataRegistry::clear();
    }

    public function testSeederGetsRepositoriesAndProvidersOfFeatureModules(): void
    {
        [$app, $out] = $this->console(AppModule::class);
        self::assertSame(0, $app->run(['db:seed', SeedCatsSeeder::class]), $out->fetch());
        self::assertSame(['Ace', 'Kit', 'Tom', 'Zed'], SeedCatsSeeder::$seeded);
    }

    public function testInjectRepositoryWithoutAFeatureModule(): void
    {
        [$app, $out] = $this->console(SeedOrmOnlyModule::class);
        self::assertSame(0, $app->run(['db:seed', SeedUnregisteredSeeder::class]), $out->fetch());
        self::assertSame(1, SeedUnregisteredSeeder::$count);
    }

    /** @return array{0: Application, 1: Output} */
    private function console(string $root): array
    {
        $out = Output::memory();
        $ctx = new ProjectContext(dirname(__DIR__, 2), ['rootModule' => $root]);

        return [(new Application())->setOutput($out)->setContext($ctx)->add(...BuiltIn::commands()), $out];
    }
}
