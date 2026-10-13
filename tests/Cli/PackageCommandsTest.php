<?php

declare(strict_types=1);

namespace Nesto\Tests\Cli;

use Nesto\Cli\Application;
use Nesto\Cli\Commands\BuiltIn;
use Nesto\Cli\Output;
use Nesto\Cli\ProjectContext;
use Nesto\Core\NestoFactory;
use Nesto\Tests\Cli\Fixtures\BareModule;
use Nesto\Tests\Cli\Fixtures\OrmAppModule;
use Nesto\Tests\Cli\Fixtures\QueueAppModule;
use Nesto\Tests\Cli\Fixtures\ScheduleAppModule;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/PackageModules.php';

/** migrate*, schema:sync, queue:work, schedule:run against the real packages (skipped when a package is absent). */
final class PackageCommandsTest extends TestCase
{
    private string $dir;
    private Output $out;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nesto-pkg-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/database/migrations', 0777, true);
        file_put_contents($this->dir . '/composer.json', '{}');
        $this->out = Output::memory();
    }

    protected function tearDown(): void
    {
        GeneratorTest::rrmdir($this->dir);
    }

    /** $shareApp: one app for every command (the CLI closes the apps it boots after each command; ':memory:' would be lost). */
    private function app(string $rootModule, bool $shareApp = false): Application
    {
        $ctx = new ProjectContext($this->dir, ['rootModule' => $rootModule, 'migrations' => 'database/migrations']);
        if ($shareApp) {
            $ctx->setApp(NestoFactory::createApplicationContext($rootModule));
        }

        return (new Application())->setOutput($this->out)->setContext($ctx)->add(...BuiltIn::commands());
    }

    public function testMigrationLifecycle(): void
    {
        if (! class_exists(\Nesto\Orm\DataSource::class)) {
            self::markTestSkipped('ORM package not present');
        }
        $app = $this->app(OrmAppModule::class, shareApp: true);

        self::assertSame(0, $app->run(['migrate:generate', 'create_things_table']));
        $files = glob($this->dir . '/database/migrations/*_create_things_table.php');
        self::assertCount(1, $files);
        GeneratorTest::assertLints($files[0]);

        self::assertSame(0, $app->run(['migrate:status']), $this->out->fetch());
        self::assertStringContainsString('Pending', $this->out->fetch());

        $this->out->clear();
        self::assertSame(0, $app->run(['migrate']), $this->out->fetch());
        self::assertStringContainsString('CreateThingsTable', $this->out->fetch());
        self::assertStringContainsString('1 migration(s)', $this->out->fetch());

        $this->out->clear();
        self::assertSame(0, $app->run(['migrate:status']));
        self::assertStringContainsString('Ran', $this->out->fetch());
        self::assertStringContainsString('0 pending', $this->out->fetch());

        $this->out->clear();
        self::assertSame(0, $app->run(['migrate']));
        self::assertStringContainsString('Nothing to migrate', $this->out->fetch());

        $this->out->clear();
        self::assertSame(0, $app->run(['migrate:revert']));
        self::assertStringContainsString('Reverted', $this->out->fetch());

        $this->out->clear();
        self::assertSame(0, $app->run(['migrate:status']));
        self::assertStringContainsString('1 pending', $this->out->fetch());

        $this->out->clear();
        self::assertSame(0, $app->run(['schema:sync']));
        self::assertStringContainsString('Schema synchronized', $this->out->fetch());
    }

    public function testOrmCommandsWhenOrmModuleIsNotImported(): void
    {
        if (! class_exists(\Nesto\Orm\DataSource::class)) {
            self::markTestSkipped('ORM package not present');
        }
        $app = $this->app(BareModule::class);
        self::assertSame(1, $app->run(['migrate']));
        self::assertStringContainsString('not configured', $this->out->fetch());
        self::assertStringContainsString('OrmModule::forRoot', $this->out->fetch());
    }

    public function testQueueWorkOnce(): void
    {
        if (! class_exists(\Nesto\Queue\Worker::class)) {
            self::markTestSkipped('Queue package not present');
        }
        $app = $this->app(QueueAppModule::class);
        self::assertSame(0, $app->run(['queue:work', '--once', '--sleep=0', '--queue=default']), $this->out->fetch());
        self::assertStringContainsString('Processed 0 job(s)', $this->out->fetch());

        $this->out->clear();
        self::assertSame(1, $this->app(BareModule::class)->run(['queue:work', '--once']));
        self::assertStringContainsString('not configured', $this->out->fetch());
    }

    public function testScheduleRun(): void
    {
        if (! class_exists(\Nesto\Schedule\Scheduler::class)) {
            self::markTestSkipped('Schedule package not present');
        }
        $app = $this->app(ScheduleAppModule::class);
        self::assertSame(0, $app->run(['schedule:run']), $this->out->fetch());
        self::assertStringContainsString('No scheduled jobs found', $this->out->fetch());

        $this->out->clear();
        self::assertSame(1, $this->app(BareModule::class)->run(['schedule:run']));
        self::assertStringContainsString('not configured', $this->out->fetch());
    }
}
