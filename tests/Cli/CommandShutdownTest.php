<?php

declare(strict_types=1);

namespace Nesto\Tests\Cli\Shutdown;

use Nesto\Cli\Application;
use Nesto\Cli\Command;
use Nesto\Cli\Output;
use Nesto\Cli\ProjectContext;
use Nesto\Common\Attributes\Injectable;
use Nesto\Common\Attributes\Module;
use Nesto\Common\Contracts\BeforeApplicationShutdown;
use Nesto\Common\Contracts\OnApplicationShutdown;
use Nesto\Common\Contracts\OnModuleDestroy;
use PHPUnit\Framework\TestCase;

#[Injectable]
final class HookedService implements OnModuleDestroy, BeforeApplicationShutdown, OnApplicationShutdown
{
    /** @var list<string> */
    public static array $log = [];

    public function onModuleDestroy(): void
    {
        self::$log[] = 'destroy';
    }

    public function beforeApplicationShutdown(?string $signal = null): void
    {
        self::$log[] = 'before';
    }

    public function onApplicationShutdown(?string $signal = null): void
    {
        self::$log[] = 'shutdown';
    }
}

#[Module(providers: [HookedService::class])]
final class HookedModule
{
}

final class UsesAppCommand extends Command
{
    protected string $signature = 'uses-app';

    public function handle(): int
    {
        $this->get(HookedService::class);
        HookedService::$log[] = 'handled';

        return self::SUCCESS;
    }
}

final class FailsCommand extends Command
{
    protected string $signature = 'fails';

    public function handle(): int
    {
        $this->get(HookedService::class);

        throw new \RuntimeException('boom');
    }
}

final class CallsOtherCommand extends Command
{
    protected string $signature = 'calls-other';

    public function handle(): int
    {
        $this->get(HookedService::class);
        $this->call('uses-app');
        HookedService::$log[] = 'outer-done';

        return self::SUCCESS;
    }
}

final class NoAppCommand extends Command
{
    protected string $signature = 'no-app';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}

/** `php nesto <command>` closes the application it booted, so shutdown hooks run (Nest's app.close()). */
final class CommandShutdownTest extends TestCase
{
    private ProjectContext $context;
    private Application $app;

    protected function setUp(): void
    {
        \Nesto\Logger\Logger::toStream(fopen('php://memory', 'w+b')); // a failing command is logged
        HookedService::$log = [];
        $this->context = new ProjectContext(sys_get_temp_dir(), ['rootModule' => HookedModule::class]);
        $this->app = (new Application())->setOutput(Output::memory())->setContext($this->context)
            ->add(new UsesAppCommand(), new FailsCommand(), new CallsOtherCommand(), new NoAppCommand());
    }

    protected function tearDown(): void
    {
        \Nesto\Logger\Logger::toStream(null);
    }

    public function test_the_app_is_closed_after_the_command(): void
    {
        self::assertSame(0, $this->app->run(['uses-app']));
        self::assertSame(['handled', 'destroy', 'before', 'shutdown'], HookedService::$log);
        self::assertFalse($this->context->hasApp());

        // the next command boots a fresh application
        HookedService::$log = [];
        self::assertSame(0, $this->app->run(['uses-app']));
        self::assertSame(['handled', 'destroy', 'before', 'shutdown'], HookedService::$log);
    }

    public function test_the_app_is_closed_when_the_command_fails(): void
    {
        self::assertSame(1, $this->app->run(['fails']));
        self::assertSame(['destroy', 'before', 'shutdown'], HookedService::$log);
    }

    public function test_the_app_is_closed_when_exceptions_propagate(): void
    {
        $this->app->setCatchExceptions(false);
        try {
            $this->app->run(['fails']);
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame(['destroy', 'before', 'shutdown'], HookedService::$log);
    }

    public function test_a_called_command_does_not_close_the_app_of_the_caller(): void
    {
        self::assertSame(0, $this->app->run(['calls-other']));
        self::assertSame(['handled', 'outer-done', 'destroy', 'before', 'shutdown'], HookedService::$log);
    }

    public function test_an_app_that_was_never_booted_is_left_alone(): void
    {
        self::assertSame(0, $this->app->run(['no-app']));
        self::assertSame([], HookedService::$log);
        self::assertFalse($this->context->hasApp());
    }
}
