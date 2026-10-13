<?php

declare(strict_types=1);

namespace Nesto\Tests\Cli;

use Nesto\Cli\Application;
use Nesto\Cli\Commands\BuiltIn;
use Nesto\Cli\Output;
use Nesto\Cli\ProjectContext;
use Nesto\Tests\Testing\Fixtures\CatsModule;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Testing/Fixtures/Fixtures.php';

/** Commands that boot the application context (routes, info, migrate...). */
final class AppCommandsTest extends TestCase
{
    private Output $out;
    private Application $app;

    protected function setUp(): void
    {
        $this->out = Output::memory();
        $ctx = new ProjectContext(dirname(__DIR__, 2), ['rootModule' => CatsModule::class]);
        $this->app = (new Application())->setOutput($this->out)->setContext($ctx)->add(...BuiltIn::commands());
    }

    public function testRoutesListsRoutesOfTheRootModule(): void
    {
        self::assertSame(0, $this->app->run(['routes']));
        $text = $this->out->fetch();
        self::assertStringContainsString('METHOD', $text);
        self::assertStringContainsString('/cats/{id}', $text);
        self::assertStringContainsString('CatsController::index', $text);
        self::assertStringContainsString('CatsModule', $text);

        $this->out->clear();
        self::assertSame(0, $this->app->run(['route:list', '--method=POST', '--json']));
        $json = json_decode($this->out->fetch(), true);
        self::assertCount(1, $json);
        self::assertSame('POST', $json[0]['method']);
    }

    public function testRoutesShowsTheUrlsConfiguredInTheBootstrapFile(): void
    {
        $ctx = new ProjectContext(__DIR__ . '/Fixtures/routes-app', ['rootModule' => \Nesto\Tests\Cli\Fixtures\RoutesAppModule::class]);
        $app = (new Application())->setOutput($this->out)->setContext($ctx)->add(...BuiltIn::commands());

        self::assertSame(0, $app->run(['routes']));
        $text = $this->out->fetch();
        self::assertStringContainsString('/api/v1/cats/{id}', $text, 'global prefix + version from public/index.php');
        self::assertStringContainsString('/v1/admin', $text);
        self::assertStringContainsString('HOST', $text);
        self::assertStringContainsString(':tenant.example.com', $text);
        self::assertStringNotContainsString('swallowed', $text);

        $this->out->clear();
        self::assertSame(0, $app->run(['routes', '--json', '--path=admin']));
        self::assertSame([['method' => 'GET', 'path' => '/v1/admin', 'handler' => \Nesto\Tests\Cli\Fixtures\TenantAdminController::class . '::index', 'module' => 'RoutesAppModule', 'host' => ':tenant.example.com']], json_decode($this->out->fetch(), true));
    }

    public function testInfo(): void
    {
        self::assertSame(0, $this->app->run(['info']));
        $text = $this->out->fetch();
        self::assertStringContainsString(PHP_VERSION, $text);
        self::assertStringContainsString('Cli', $text);
        self::assertStringContainsString('Testing', $text);
        self::assertStringContainsString(CatsModule::class, $text);
    }

    public function testOrmQueueScheduleCommandsFailGracefullyWhenPackageMissing(): void
    {
        foreach (['migrate', 'migrate:revert', 'migrate:status', 'schema:sync', 'queue:work', 'schedule:run', 'schedule:work'] as $command) {
            $this->out->clear();
            $code = $this->app->run([$command]);
            $text = $this->out->fetch();
            if (class_exists(\Nesto\Orm\DataSource::class) && str_starts_with($command, 'migrate') || str_starts_with($command, 'schema')) {
                continue; // another package is present; its behaviour is not under test here
            }
            self::assertNotSame('', $text, $command);
            self::assertTrue($code === 1 || str_contains($text, 'not installed') || str_contains($text, 'not configured') || str_contains($text, 'No migration files'), "$command should print a friendly message");
        }
    }

    public function testCustomCommandsResolveServicesFromTheAppContext(): void
    {
        $command = new class extends \Nesto\Cli\Command {
            protected string $signature = 'cats:service';

            public function handle(): int
            {
                $this->line(get_class($this->get(\Nesto\Tests\Testing\Fixtures\CatsService::class)));

                return self::SUCCESS;
            }
        };
        $this->app->add($command);

        self::assertSame(0, $this->app->run(['cats:service']));
        self::assertStringContainsString('CatsService', $this->out->fetch());
    }

    public function testDbSeedWithMissingSeeder(): void
    {
        self::assertSame(1, $this->app->run(['db:seed', 'NopeSeeder']));
        self::assertStringContainsString('not found', $this->out->fetch());
    }

    public function testRoutesWithoutRootModuleIsAFriendlyError(): void
    {
        $app = (new Application())->setOutput($this->out)->setContext(new ProjectContext(sys_get_temp_dir(), ['rootModule' => 'App\\Missing']))->add(...BuiltIn::commands());
        self::assertSame(1, $app->run(['routes']));
        self::assertStringContainsString('Root module "App\\Missing" was not found', $this->out->fetch());
    }

    public function testProjectContextDiscovery(): void
    {
        $ctx = ProjectContext::discover(__DIR__);
        self::assertNotNull($ctx);
        self::assertSame(dirname(__DIR__, 2), $ctx->rootDir());
        self::assertSame('src', $ctx->config('sourceRoot'));
        self::assertNull(ProjectContext::discover('/'));
    }
}
