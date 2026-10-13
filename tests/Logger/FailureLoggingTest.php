<?php

declare(strict_types=1);

namespace Nesto\Tests\Logger;

use Nesto\Cli\Application;
use Nesto\Cli\Command;
use Nesto\Cli\Output;
use Nesto\Logger\Logger;
use Nesto\Queue\Drivers\SyncDriver;
use Nesto\Queue\Job;
use Nesto\Queue\JobRunner;
use Nesto\Queue\ProcessorRegistry;
use Nesto\Schedule\Scheduler;
use Nesto\Schedule\SchedulerRegistry;
use PHPUnit\Framework\TestCase;

final class ExplodingCommand extends Command
{
    protected string $signature = 'explode';

    public function handle(): int
    {
        throw new \LogicException('command blew up');
    }
}

/** Failures the framework catches outside HTTP (queue jobs, scheduled jobs, commands) reach the log without a LoggerModule. */
final class FailureLoggingTest extends TestCase
{
    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        $this->stream = fopen('php://memory', 'w+b');
        Logger::toStream($this->stream);
    }

    protected function tearDown(): void
    {
        Logger::toStream(null);
    }

    private function captured(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    public function testFailedQueueJobIsLogged(): void
    {
        $registry = new ProcessorRegistry();
        $registry->register('mail', 'send', fn () => throw new \RuntimeException('smtp down'));
        $driver = new SyncDriver($registry);

        (new JobRunner($registry))->run(new Job('1', 'mail', 'send', opts: ['attempts' => 1]), $driver);

        self::assertStringContainsString('ERROR [Queue] Job "send" (1) on queue "mail" failed: smtp down', $this->captured());
        self::assertStringContainsString('RuntimeException: smtp down', $this->captured());
    }

    public function testFailedScheduledJobIsLogged(): void
    {
        $registry = new SchedulerRegistry();
        $registry->addCronJob('nightly', '* * * * *', fn () => throw new \RuntimeException('report failed'));
        $scheduler = new Scheduler($registry, storagePath: sys_get_temp_dir() . '/nesto-failure-logging');
        $start = new \DateTimeImmutable('2026-10-13 10:00:00');
        $scheduler->tick($start);
        $scheduler->tick($start->modify('+1 minute'));

        self::assertStringContainsString('ERROR [Scheduler] Scheduled cron "nightly" failed: report failed', $this->captured());
    }

    public function testCommandExceptionIsLoggedWithItsTrace(): void
    {
        $out = Output::memory();
        $app = (new Application())->setOutput($out)->add(new ExplodingCommand());

        self::assertSame(1, $app->run(['explode']));
        self::assertStringContainsString('ERROR [Console] Command "explode" failed: command blew up', $this->captured());
        self::assertStringContainsString('LogicException: command blew up', $this->captured());
    }
}
