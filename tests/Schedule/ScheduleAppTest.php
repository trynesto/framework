<?php

declare(strict_types=1);

namespace Nesto\Tests\Schedule;

use DateTimeImmutable;
use Nesto\Common\Attributes\Injectable;
use Nesto\Common\Attributes\Module;
use Nesto\Core\NestoFactory;
use Nesto\Schedule\Attributes\Cron;
use Nesto\Schedule\Attributes\Interval;
use Nesto\Schedule\Attributes\Timeout;
use Nesto\Schedule\CronExpression;
use Nesto\Schedule\Scheduler;
use Nesto\Schedule\SchedulerRegistry;
use Nesto\Schedule\ScheduleModule;
use PHPUnit\Framework\TestCase;

#[Injectable]
final class TasksService
{
    /** @var list<string> */
    public array $log = [];

    #[Cron(CronExpression::EVERY_MINUTE)]
    public function everyMinute(): void
    {
        $this->log[] = 'minute';
    }

    #[Cron('0 9 * * *', name: 'morning', timeZone: 'UTC')]
    public function morning(): void
    {
        $this->log[] = 'morning';
    }

    #[Interval(500, name: 'poll')]
    public function poll(): void
    {
        $this->log[] = 'poll';
    }

    #[Timeout(100, name: 'warm')]
    public function warm(): void
    {
        $this->log[] = 'warm';
    }

    #[Cron(CronExpression::EVERY_HOUR, name: 'broken')]
    public function broken(): void
    {
        throw new \RuntimeException('job failed');
    }
}

#[Module(imports: [new ScheduleModule()], providers: [TasksService::class])]
final class ScheduleAppModule
{
}

final class ScheduleAppTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        \Nesto\Logger\Logger::toStream(fopen('php://memory', 'w+b')); // failed jobs are logged: keep the test output clean
        $this->storage = sys_get_temp_dir() . '/nesto-schedule-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        \Nesto\Logger\Logger::toStream(null);
        if (is_file($this->storage . '/state.json')) {
            unlink($this->storage . '/state.json');
        }
        if (is_dir($this->storage)) {
            rmdir($this->storage);
        }
    }

    public function testExplorerRegistersAttributesAndTickRunsDueJobs(): void
    {
        $app = NestoFactory::create(ScheduleAppModule::class)->init();
        $registry = $app->get(SchedulerRegistry::class);
        $scheduler = $app->get(Scheduler::class);
        $tasks = $app->get(TasksService::class);

        self::assertSame([TasksService::class . '::everyMinute', 'morning', 'broken'], array_keys($registry->getCronJobs()));
        self::assertSame(['poll'], array_keys($registry->getIntervals()));
        self::assertSame(['warm'], array_keys($registry->getTimeouts()));

        $ran = $scheduler->tick(new DateTimeImmutable('2026-10-12 10:00:00', new \DateTimeZone('UTC')));
        self::assertSame([TasksService::class . '::everyMinute', 'broken'], $ran);
        self::assertSame(['minute'], $tasks->log);
        self::assertArrayHasKey('broken', $scheduler->errors());

        // same minute again: nothing (cron), interval/timeout not elapsed yet
        self::assertSame([], $scheduler->tick(new DateTimeImmutable('2026-10-12 10:00:00.05', new \DateTimeZone('UTC'))));

        // one second later: interval (500ms) and timeout (100ms) fire, cron does not
        $ran = $scheduler->tick(new DateTimeImmutable('2026-10-12 10:00:01', new \DateTimeZone('UTC')));
        self::assertSame(['poll', 'warm'], $ran);

        // next minute: cron again, interval again, timeout never again
        $ran = $scheduler->tick(new DateTimeImmutable('2026-10-12 10:01:00', new \DateTimeZone('UTC')));
        self::assertSame([TasksService::class . '::everyMinute', 'poll'], $ran);

        // 09:00 UTC: morning job
        $ran = $scheduler->tick(new DateTimeImmutable('2026-10-13 09:00:00', new \DateTimeZone('UTC')));
        self::assertContains('morning', $ran);
        self::assertSame(['minute', 'poll', 'warm', 'minute', 'poll', 'minute', 'morning', 'poll'], $tasks->log);
        self::assertSame(3, $registry->getCronJob(TasksService::class . '::everyMinute')['runs']);
    }

    public function testJobsListsEveryRegisteredJob(): void
    {
        $scheduler = NestoFactory::create(ScheduleAppModule::class)->init()->get(Scheduler::class);

        self::assertSame([
            ['type' => 'cron', 'name' => TasksService::class . '::everyMinute', 'schedule' => '* * * * *'],
            ['type' => 'cron', 'name' => 'morning', 'schedule' => '0 9 * * * (UTC)'],
            ['type' => 'cron', 'name' => 'broken', 'schedule' => '0 * * * *'],
            ['type' => 'interval', 'name' => 'poll', 'schedule' => 'every 500 ms'],
            ['type' => 'timeout', 'name' => 'warm', 'schedule' => 'once after 100 ms'],
        ], $scheduler->jobs());
    }

    public function testRunDueOnceUsesFileStateToAvoidDoubleRuns(): void
    {
        $app = NestoFactory::create(ScheduleModule::forRoot(['storagePath' => $this->storage]))->init();
        $registry = $app->get(SchedulerRegistry::class);
        $scheduler = $app->get(Scheduler::class);
        $runs = 0;
        $registry->addCronJob('counter', CronExpression::EVERY_MINUTE, function () use (&$runs) { $runs++; });

        $now = new DateTimeImmutable('2026-10-12 10:00:05');
        self::assertSame(['counter'], $scheduler->runDueOnce($now));
        self::assertSame([], $scheduler->runDueOnce($now->modify('+20 seconds')));   // system cron fired twice in the same minute
        self::assertSame(['counter'], $scheduler->runDueOnce($now->modify('+1 minute')));
        self::assertSame(2, $runs);
        self::assertFileExists($this->storage . '/state.json');

        // a fresh process (new Scheduler) sees the persisted state
        $fresh = $app->get(Scheduler::class);
        self::assertSame($scheduler, $fresh);
        $other = new Scheduler($registry, storagePath: $this->storage);
        self::assertSame([], $other->runDueOnce($now->modify('+1 minute')));
    }

    public function testRegistryValidation(): void
    {
        $registry = new SchedulerRegistry;
        $registry->addCronJob('a', '* * * * *', fn () => null);
        self::assertTrue($registry->doesExist('cron', 'a'));
        $registry->deleteCronJob('a');
        self::assertFalse($registry->doesExist('cron', 'a'));

        $this->expectException(\InvalidArgumentException::class);
        $registry->addCronJob('b', 'not a cron', fn () => null);
    }
}
