<?php

declare(strict_types=1);

namespace Nesto\Tests\Queue;

use Nesto\Queue\Drivers\DatabaseDriver;
use Nesto\Queue\Drivers\FileDriver;
use Nesto\Queue\Job;
use Nesto\Queue\JobRunner;
use Nesto\Queue\ProcessorRegistry;
use Nesto\Queue\QueueDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DriversTest extends TestCase
{

    private static function tempDir(): string
    {
        return sys_get_temp_dir() . '/nesto-queue-drivers-' . getmypid();
    }

    public static function tearDownAfterClass(): void
    {
        $dir = self::tempDir();
        foreach (glob($dir . '/{,*/,*/*/}{.,}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        foreach (array_reverse(glob($dir . '/{*/*,*}', GLOB_BRACE | GLOB_ONLYDIR) ?: []) as $d) {
            @rmdir($d);
        }
        @rmdir($dir);
    }

    /** @return array<string, array{\Closure(): QueueDriver}> */
    public static function drivers(): array
    {
        return [
            'file' => [fn () => new FileDriver(self::tempDir() . '/' . bin2hex(random_bytes(3)))],
            'database' => [fn () => new DatabaseDriver(new \PDO('sqlite::memory:'))],
        ];
    }

    /** @param \Closure(): QueueDriver $factory */
    #[DataProvider('drivers')]
    public function testClaimReleaseFailAndClean(\Closure $factory): void
    {
        $driver = $factory();
        $job = $driver->push(Job::create('q', 'work', ['n' => 1], ['attempts' => 2, 'backoff' => 50]));
        self::assertSame(1, $driver->size('q'));
        self::assertNull($driver->pop('other'));

        $claimed = $driver->pop('q');
        self::assertNotNull($claimed);
        self::assertSame($job->id, $claimed->id);
        self::assertSame(Job::ACTIVE, $claimed->status);
        self::assertNull($driver->pop('q'), 'an active job cannot be claimed twice');
        self::assertSame(0, $driver->size('q'));

        $claimed->attemptsMade = 1;
        $driver->release($claimed, 50);
        self::assertSame(Job::DELAYED, $driver->getJob('q', $job->id)->status);
        self::assertNull($driver->pop('q'), 'delayed job is not ready yet');
        usleep(70_000);
        $again = $driver->pop('q');
        self::assertNotNull($again);
        self::assertSame(1, $again->attemptsMade);

        $again->status = Job::FAILED;
        $again->failedReason = 'nope';
        $again->finishedOn = microtime(true);
        $driver->fail($again);
        self::assertSame('nope', $driver->getJob('q', $job->id)->failedReason);
        self::assertSame(1, $driver->clean('q', 'failed'));
        self::assertNull($driver->getJob('q', $job->id));
    }

    /** @param \Closure(): QueueDriver $factory */
    #[DataProvider('drivers')]
    public function testPriorityOrderAndRemoveOnComplete(\Closure $factory): void
    {
        $driver = $factory();
        $driver->push(Job::create('p', 'a', [], ['priority' => 10]));
        $driver->push(Job::create('p', 'b', [], ['priority' => 1, 'removeOnComplete' => true]));
        $driver->push(Job::create('p', 'c', [], ['priority' => 5]));

        $registry = new ProcessorRegistry;
        $order = [];
        $registry->register('p', null, function (Job $job) use (&$order) { $order[] = $job->name; });
        $runner = new JobRunner($registry);
        while (($job = $driver->pop('p')) !== null) {
            $runner->run($job, $driver);
        }

        self::assertSame(['b', 'c', 'a'], $order);
        self::assertSame(2, $driver->clean('p'), 'b was removed on completion, a and c are cleaned');
    }

    public function testExponentialBackoff(): void
    {
        $job = Job::create('q', 'x', [], ['backoff' => ['type' => 'exponential', 'delay' => 100]]);
        $job->attemptsMade = 1;
        self::assertSame(100, JobRunner::backoff($job));
        $job->attemptsMade = 3;
        self::assertSame(400, JobRunner::backoff($job));
        self::assertSame(250, JobRunner::backoff(Job::create('q', 'x', [], ['backoff' => 250])));
    }

    public function testJobRoundTrip(): void
    {
        $job = Job::create('q', 'x', ['a' => 1], ['delay' => 10, 'jobId' => 'custom']);
        $job->progress(['step' => 2]);
        $copy = Job::fromArray(json_decode(json_encode($job->toArray()), true));

        self::assertSame('custom', $copy->id);
        self::assertSame(Job::DELAYED, $copy->status);
        self::assertSame(['step' => 2], $copy->progress());
        self::assertSame(3, $copy->attempts());
        self::assertEqualsWithDelta($job->availableAt, $copy->availableAt, 0.001);
    }
}
