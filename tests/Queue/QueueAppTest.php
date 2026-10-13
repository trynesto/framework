<?php

declare(strict_types=1);

namespace Nesto\Tests\Queue;

use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Injectable;
use Nesto\Common\Attributes\Module;
use Nesto\Common\Attributes\Post;
use Nesto\Common\Attributes\Query;
use Nesto\Common\DynamicModule;
use Nesto\Core\NestoFactory;
use Nesto\Http\Request;
use Nesto\Queue\Attributes\InjectQueue;
use Nesto\Queue\Attributes\OnQueueActive;
use Nesto\Queue\Attributes\OnQueueCompleted;
use Nesto\Queue\Attributes\OnQueueFailed;
use Nesto\Queue\Attributes\Process;
use Nesto\Queue\Attributes\Processor;
use Nesto\Queue\Drivers\DatabaseDriver;
use Nesto\Queue\Drivers\FileDriver;
use Nesto\Queue\Drivers\SyncDriver;
use Nesto\Queue\Job;
use Nesto\Queue\Queue;
use Nesto\Queue\QueueDriver;
use Nesto\Queue\QueueFeatureModule;
use Nesto\Queue\QueueModule;
use Nesto\Queue\Worker;
use PHPUnit\Framework\TestCase;

#[Processor('mail')]
#[Injectable]
final class MailProcessor
{
    /** @var list<string> */
    public array $log = [];
    public int $flakyAttempts = 0;

    #[Process('send')]
    public function send(Job $job): string
    {
        $this->log[] = 'send:' . $job->data['to'];
        $job->progress(100);

        return 'sent-' . $job->data['to'];
    }

    #[Process]
    public function fallback(Job $job): void
    {
        $this->log[] = 'default:' . $job->name;
    }

    #[Process('flaky')]
    public function flaky(Job $job): void
    {
        $this->flakyAttempts++;
        if ($job->attemptsMade < 3) {
            throw new \RuntimeException('try again');
        }
        $this->log[] = 'flaky:ok';
    }

    #[Process('doomed')]
    public function doomed(Job $job): void
    {
        throw new \RuntimeException('always');
    }

    #[OnQueueActive]
    public function active(Job $job): void
    {
        $this->log[] = 'active:' . $job->name;
    }

    #[OnQueueCompleted]
    public function completed(Job $job, mixed $result): void
    {
        $this->log[] = 'completed:' . $job->name . ':' . var_export($result, true);
    }

    #[OnQueueFailed]
    public function failed(Job $job, \Throwable $e): void
    {
        $this->log[] = 'failed:' . $job->name . ':' . $job->attemptsMade . ':' . $e->getMessage();
    }
}

#[Controller('mail')]
final class MailController
{
    public function __construct(#[InjectQueue('mail')] private readonly Queue $mail)
    {
    }

    #[Post()]
    public function send(#[Query('to')] string $to, #[Query('name')] string $name = 'send'): array
    {
        $job = $this->mail->add($name, ['to' => $to], ['attempts' => 3]);

        return ['id' => $job->id, 'status' => $job->status, 'count' => $this->mail->count()];
    }
}

#[Module(imports: [new QueueModule(driver: 'sync'), new QueueFeatureModule('mail')], controllers: [MailController::class], providers: [MailProcessor::class])]
final class SyncQueueAppModule
{
}

#[Module(imports: [new QueueFeatureModule('mail')], controllers: [MailController::class], providers: [MailProcessor::class])]
final class QueueFeatureAppModule
{
}

final class QueueAppTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nesto-queue-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/*/{.,}*', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
            foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
                rmdir($d);
            }
            rmdir($this->dir);
        }
    }

    public function testSyncDriverProcessesJobsInlineThroughTheApp(): void
    {
        $app = NestoFactory::create(SyncQueueAppModule::class);
        self::assertInstanceOf(SyncDriver::class, $app->get(QueueDriver::class));
        $processor = $app->get(MailProcessor::class);

        $res = $app->handle(Request::create('POST', '/mail', ['to' => 'a@b.c']));
        self::assertSame(201, $res->getStatusCode());
        $body = json_decode((string) $res->getBody(), true);
        self::assertSame('completed', $body['status']);
        self::assertSame(0, $body['count']);
        self::assertSame(['active:send', 'send:a@b.c', "completed:send:'sent-a@b.c'"], $processor->log);

        $queue = $app->get(QueueModule::queueToken('mail'));
        self::assertInstanceOf(Queue::class, $queue);
        $job = $queue->getJob($body['id']);
        self::assertSame(100, $job->progress());
        self::assertSame('sent-a@b.c', $job->returnvalue);

        // default handler, retries and final failure
        $processor->log = [];
        $app->handle(Request::create('POST', '/mail', ['to' => 'x', 'name' => 'other']));
        $app->handle(Request::create('POST', '/mail', ['to' => 'x', 'name' => 'flaky']));
        $doomed = json_decode((string) $app->handle(Request::create('POST', '/mail', ['to' => 'x', 'name' => 'doomed']))->getBody(), true);

        self::assertSame([
            'active:other', 'default:other', 'completed:other:NULL',
            'active:flaky', 'failed:flaky:1:try again', 'active:flaky', 'failed:flaky:2:try again', 'active:flaky', 'flaky:ok', 'completed:flaky:NULL',
            'active:doomed', 'failed:doomed:1:always', 'active:doomed', 'failed:doomed:2:always', 'active:doomed', 'failed:doomed:3:always',
        ], $processor->log);
        self::assertSame(3, $processor->flakyAttempts);
        self::assertSame('failed', $doomed['status']);
        self::assertSame('always', $queue->getJob($doomed['id'])->failedReason);
        self::assertSame(4, $queue->clean());
    }

    public function testFileDriverWithWorkerOnce(): void
    {
        $root = new DynamicModule(module: QueueFeatureAppModule::class, imports: [QueueModule::forRoot(['driver' => 'file', 'path' => $this->dir])]);
        $app = NestoFactory::create($root);
        self::assertInstanceOf(FileDriver::class, $app->get(QueueDriver::class));
        $processor = $app->get(MailProcessor::class);

        $body = json_decode((string) $app->handle(Request::create('POST', '/mail', ['to' => 'file@x']))->getBody(), true);
        self::assertSame('waiting', $body['status']);
        self::assertSame(1, $body['count']);
        self::assertSame([], $processor->log);
        self::assertFileExists($this->dir . '/mail/' . $body['id'] . '.json');

        $queue = $app->get(QueueModule::queueToken('mail'));
        $queue->add('flaky', ['to' => 'f'], ['attempts' => 3, 'backoff' => 0]);
        self::assertSame(2, $queue->count());

        $worker = $app->get(Worker::class);
        $worker->work(['mail'], ['once' => true]);

        self::assertSame(4, $worker->processed(), 'send once + flaky three attempts');
        self::assertSame(0, $queue->count());
        self::assertSame('completed', $queue->getJob($body['id'])->status);
        self::assertSame(100, $queue->getJob($body['id'])->progress());
        self::assertContains('send:file@x', $processor->log);
        self::assertContains('flaky:ok', $processor->log);
        self::assertSame(3, $processor->flakyAttempts);
        self::assertSame(2, $queue->clean());
    }

    public function testDatabaseDriverWithWorker(): void
    {
        $root = new DynamicModule(
            module: QueueFeatureAppModule::class,
            imports: [QueueModule::forRoot(['driver' => 'database', 'connection' => 'sqlite::memory:'])],
        );
        $app = NestoFactory::create($root)->init();
        self::assertInstanceOf(DatabaseDriver::class, $app->get(QueueDriver::class));
        $processor = $app->get(MailProcessor::class);
        $queue = $app->get(QueueModule::queueToken('mail'));

        $low = $queue->add('send', ['to' => 'low'], ['priority' => 5]);
        $high = $queue->add('send', ['to' => 'high'], ['priority' => 1]);
        $later = $queue->add('send', ['to' => 'later'], ['delay' => 60_000]);
        self::assertSame('delayed', $later->status);
        self::assertSame(3, $queue->count());

        $app->get(Worker::class)->work([], ['once' => true]);

        self::assertSame(['active:send', 'send:high', "completed:send:'sent-high'", 'active:send', 'send:low', "completed:send:'sent-low'"], $processor->log);
        self::assertSame(1, $queue->count());
        self::assertSame('completed', $queue->getJob($high->id)->status);
        self::assertSame('delayed', $queue->getJob($later->id)->status);

        $queue->pause();
        self::assertTrue($queue->isPaused());
        self::assertNull($app->get(QueueDriver::class)->pop('mail'));
        $queue->resume();
        self::assertFalse($queue->isPaused());
    }

    public function testQueueTokenAndRegisterQueue(): void
    {
        self::assertSame('NestoQueue<mail>', QueueModule::queueToken('mail'));
        $module = QueueModule::registerQueue('a', 'b');
        self::assertSame(['NestoQueue<a>', 'NestoQueue<b>'], $module->exports);
        self::assertSame(QueueModule::class, $module->module);
    }
}
