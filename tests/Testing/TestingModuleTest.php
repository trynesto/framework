<?php

declare(strict_types=1);

namespace Nesto\Tests\Testing;

use Nesto\Config\ConfigModule;
use Nesto\Config\ConfigService;
use Nesto\Core\Constants;
use Nesto\Core\NestoApplication;
use Nesto\Core\Paths;
use Nesto\Tests\Cli\GeneratorTest;
use Nesto\Testing\Test;
use Nesto\Testing\TestingModule;
use Nesto\Testing\TestingModuleBuilder;
use Nesto\Tests\Testing\Fixtures\AllowGuard;
use Nesto\Tests\Testing\Fixtures\CatsController;
use Nesto\Tests\Testing\Fixtures\CatsModule;
use Nesto\Tests\Testing\Fixtures\CatsService;
use Nesto\Tests\Testing\Fixtures\CounterModule;
use Nesto\Tests\Testing\Fixtures\DenyGuard;
use Nesto\Tests\Testing\Fixtures\FakeCatsService;
use Nesto\Tests\Testing\Fixtures\GlobalGuardModule;
use Nesto\Tests\Testing\Fixtures\RequestCounter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/Fixtures.php';

final class TestingModuleTest extends TestCase
{
    public function testCreateTestingModuleReturnsBuilderAndCompilesToModule(): void
    {
        $builder = Test::createTestingModule(['imports' => [CatsModule::class]]);
        self::assertInstanceOf(TestingModuleBuilder::class, $builder);

        $module = $builder->compile();
        self::assertInstanceOf(TestingModule::class, $module);
        self::assertInstanceOf(CatsController::class, $module->get(CatsController::class));
        self::assertInstanceOf(CatsService::class, $module->get(CatsService::class));
        self::assertInstanceOf(NestoApplication::class, $module->createNestoApplication());
    }

    public function testMetadataShapesLikeModuleAttribute(): void
    {
        $module = Test::createTestingModule([
            'controllers' => [CatsController::class],
            'providers' => [CatsService::class, new \Nesto\Common\Provider('GREETING', useValue: 'yo')],
        ])->compile();

        $module->request()->get('/cats/greeting')->expect(200)->assertJson(['greeting' => 'yo']);
    }

    public function testOverrideProviderUseValueChangesTheAnswer(): void
    {
        $module = Test::createTestingModule(['imports' => [CatsModule::class]])
            ->overrideProvider(CatsService::class)->useValue(new FakeCatsService())
            ->compile();

        self::assertInstanceOf(FakeCatsService::class, $module->get(CatsService::class));
        $module->request()->get('/cats/answer')->expect(200)->assertSee('fake');
        $module->request()->get('/cats')->expect(200)->assertJsonPath('data.0.name', 'Fake');
    }

    public function testOverrideProviderUseClassAndUseFactory(): void
    {
        $module = Test::createTestingModule(['imports' => [CatsModule::class]])
            ->overrideProvider(CatsService::class)->useClass(FakeCatsService::class)
            ->compile();
        $module->request()->get('/cats/answer')->assertOk()->assertSee('fake');

        $module = Test::createTestingModule(['imports' => [CatsModule::class]])
            ->overrideProvider(CatsService::class)->useFactory(fn () => new FakeCatsService())
            ->compile();
        $module->request()->get('/cats/answer')->assertOk()->assertSee('fake');

        // factory with inject: receives the (original) GREETING value
        $module = Test::createTestingModule(['imports' => [CatsModule::class]])
            ->overrideProvider('GREETING')->useFactory(fn (string $g) => $g . '!', inject: ['GREETING'])
            ->compile();
        $module->request()->get('/cats/greeting')->assertOk()->assertJsonPath('greeting', 'hello!');
    }

    public function testOverrideValueProviderWithScalar(): void
    {
        $module = Test::createTestingModule(['imports' => [CatsModule::class]])
            ->overrideProvider('GREETING')->useValue('bonjour')
            ->compile();

        $module->request()->get('/cats/greeting')->expect(200)->assertJson(['greeting' => 'bonjour']);
    }

    public function testOverrideRequestScopedProvider(): void
    {
        $fake = new class extends RequestCounter {
            public function value(): int
            {
                return 42;
            }
        };
        $module = Test::createTestingModule(['imports' => [CounterModule::class]])
            ->overrideProvider(RequestCounter::class)->useValue($fake)
            ->compile();

        $module->request()->get('/counter')->expect(200)->assertJsonPath('value', 42);
    }

    public function testGuardDeniesWithoutOverride(): void
    {
        $module = Test::createTestingModule(['imports' => [CatsModule::class]])->compile();
        $module->request()->get('/cats/secret')->expect(403)->assertJsonPath('statusCode', 403);
    }

    public function testOverrideGuardLetsForbiddenRouteThrough(): void
    {
        $module = Test::createTestingModule(['imports' => [CatsModule::class]])
            ->overrideGuard(DenyGuard::class)->useValue(new AllowGuard())
            ->compile();

        $module->request()->get('/cats/secret')->expect(200)->assertJson(['secret' => true]);
    }

    public function testOverrideGuardRegisteredAsAppGuard(): void
    {
        $denied = Test::createTestingModule(['imports' => [GlobalGuardModule::class]])->compile();
        $denied->request()->get('/cats')->expect(403);

        $module = Test::createTestingModule(['imports' => [GlobalGuardModule::class]])
            ->overrideGuard(DenyGuard::class)->useValue(new AllowGuard())
            ->compile();
        $module->request()->get('/cats')->expect(200)->assertJsonCount(2, 'data');
    }

    public function testOverrideGuardWhenGuardIsAlsoAModuleProvider(): void
    {
        $module = Test::createTestingModule([
            'controllers' => [CatsController::class],
            'providers' => [CatsService::class, DenyGuard::class, new \Nesto\Common\Provider('GREETING', useValue: 'x')],
        ])
            ->overrideGuard(DenyGuard::class)->useClass(AllowGuard::class)
            ->compile();

        $module->request()->get('/cats/secret')->expect(200);
    }

    public function testCreateNestoApplicationAppliesOptions(): void
    {
        $module = Test::createTestingModule(['imports' => [CatsModule::class]])->compile();
        $app = $module->createNestoApplication(['globalPrefix' => 'api']);
        self::assertInstanceOf(NestoApplication::class, $app);

        $module->request()->get('/api/cats')->expect(200);
        $module->request()->get('/cats')->expect(404);
        $module->close();
    }

    public function testUseGlobalGuardsOnTheAppIsOverridden(): void
    {
        $module = Test::createTestingModule(['imports' => [CatsModule::class]])
            ->overrideGuard(DenyGuard::class)->useValue(new AllowGuard())
            ->compile();
        $module->createNestoApplication()->useGlobalGuards(DenyGuard::class);

        $module->request()->get('/cats')->expect(200);
    }

    public function testRequestBuilderVerbsAndModifiers(): void
    {
        $module = Test::createTestingModule(['imports' => [CatsModule::class]])->compile();

        $module->request()->post('/cats', ['name' => 'Garfield'])->expect(201)->assertJson(['id' => 3, 'name' => 'Garfield']);
        $module->request()->get('/cats/3')->expect(200)->assertJsonPath('name', 'Garfield');
        $module->request()->delete('/cats/3')->expect(204);
        $module->request()->get('/cats/404')->expect(404)->assertJsonPath('statusCode', 404);
        $module->request()->put('/cats/1')->expect(405);
        $module->request()->patch('/nothing')->expect(404);

        $req = $module->request()->get('/cats')->withHeader('X-A', '1')->withHeaders(['X-B' => '2'])->withBearerToken('tok')->withQuery(['page' => '2']);
        $psr = $req->toRequest();
        self::assertSame('1', $psr->header('X-A'));
        self::assertSame('2', $psr->header('X-B'));
        self::assertSame('tok', $psr->bearerToken());
        self::assertSame(['page' => '2'], $psr->query());
        self::assertSame(200, $req->send()->status());
    }

    public function testImplicitSendThroughMagicCall(): void
    {
        $module = Test::createTestingModule(['imports' => [CatsModule::class]])->compile();
        $module->request()->get('/cats')->assertOk();
        $module->request()->get('/cats/custom')->assertHeader('X-Custom', 'yes')->assertSee('plain text');

        $this->expectException(\BadMethodCallException::class);
        $module->request()->get('/cats')->nope();
    }

    public function testTheProjectRootComesFromTheImportedModulesNotFromTheFramework(): void
    {
        $project = self::externalProject();
        Paths::setBase(null);
        try {
            $module = Test::createTestingModule([
                'imports' => [new ConfigModule(), \Nesto\Tests\Testing\ExternalProject\ProjectModule::class],
            ])->compile();

            self::assertSame($project, base_path());
            self::assertSame($project . '/storage/logs', storage_path('logs'));
            self::assertSame('from-the-project', $module->get(ConfigService::class)->get('NESTO_TEST_PROJECT_MARK'));
        } finally {
            Paths::setBase(null);
        }
    }

    public function testAnExplicitBasePathStillWins(): void
    {
        self::externalProject();
        Paths::setBase('/somewhere/else');
        try {
            Test::createTestingModule(['imports' => [\Nesto\Tests\Testing\ExternalProject\ProjectModule::class]])->compile();
            self::assertSame('/somewhere/else', base_path());
        } finally {
            Paths::setBase(null);
        }
    }

    /** A module living in another project (its own composer.json and .env), like an app's AppModule. */
    private static function externalProject(): string
    {
        static $root = null;
        if ($root === null) {
            $root = realpath(sys_get_temp_dir()) . '/nesto-external-project-' . getmypid();
            @mkdir($root . '/src', 0777, true);
            file_put_contents($root . '/composer.json', '{}');
            file_put_contents($root . '/.env', "NESTO_TEST_PROJECT_MARK=from-the-project\n");
            file_put_contents($root . '/src/ProjectModule.php', "<?php\nnamespace Nesto\\Tests\\Testing\\ExternalProject;\n#[\\Nesto\\Common\\Attributes\\Module]\nfinal class ProjectModule {}\n");
            require_once $root . '/src/ProjectModule.php';
            register_shutdown_function(static fn () => GeneratorTest::rrmdir($root));
        }

        return $root;
    }

    public function testAppGuardConstantIsExposed(): void
    {
        self::assertSame('APP_GUARD', Constants::APP_GUARD);
    }
}
