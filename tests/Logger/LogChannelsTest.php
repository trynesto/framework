<?php

declare(strict_types=1);

namespace Nesto\Tests\Logger;

use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Get;
use Nesto\Common\Attributes\Module;
use Nesto\Common\Exceptions\BadRequestException;
use Nesto\Common\Exceptions\NotFoundException;
use Nesto\Common\Exceptions\ServiceUnavailableException;
use Nesto\Core\NestoFactory;
use Nesto\Http\Request;
use Nesto\Logger\Channels\FileChannel;
use Nesto\Logger\Channels\StackChannel;
use Nesto\Logger\Channels\StreamChannel;
use Nesto\Logger\LogConfig;
use Nesto\Logger\Logger;
use Nesto\Logger\LoggerModule;
use Nesto\Tests\Support\ProjectSandbox;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[Controller('errors')]
final class ErrorsController
{
    #[Get('boom')]
    public function boom(): never
    {
        throw new \RuntimeException('kaboom');
    }

    #[Get('missing')]
    public function missing(): never
    {
        throw new NotFoundException('no cat');
    }

    #[Get('bad')]
    public function bad(): never
    {
        throw new BadRequestException('bad cat');
    }

    #[Get('down')]
    public function down(): never
    {
        throw new ServiceUnavailableException('maintenance');
    }
}

#[Module(imports: [new LoggerModule()], controllers: [ErrorsController::class])]
final class ConfiguredLoggingModule
{
}

/** LOG_CHANNEL / LOG_PATH / LOG_LEVEL / LOG_DAILY / LOG_HTTP_ERRORS through LoggerModule and LogConfig. */
final class LogChannelsTest extends TestCase
{
    use ProjectSandbox;

    protected function tearDown(): void
    {
        $this->cleanSandbox();
    }

    public function testFileChannelKeepsOneHandleAndRecreatesARemovedFile(): void
    {
        $path = $this->sandbox() . '/storage/logs/app.log';
        $channel = new FileChannel($path);
        $channel->write('log', 'first', 'first');
        $channel->write('log', 'second', 'second');
        self::assertSame("first\nsecond\n", file_get_contents($path));

        unlink($path); // logrotate / rm while a worker keeps the handle
        sleep(1);      // the existence check runs at most once a second
        $channel->write('error', 'third', 'third');
        self::assertSame("third\n", file_get_contents($path));
        $channel->close();
    }

    public function testDailyFilesAreNamedByDateAndPruned(): void
    {
        $dir = $this->sandbox() . '/storage/logs';
        mkdir($dir, 0777, true);
        foreach (['2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04'] as $day) {
            touch("$dir/nesto-$day.log");
        }
        touch("$dir/nesto.log"); // not a daily file: never pruned
        $channel = new FileChannel("$dir/nesto.log", daily: true, maxFiles: 3);

        self::assertSame("$dir/nesto-2026-10-13.log", $channel->currentPath(mktime(12, 0, 0, 10, 13, 2026)));
        $channel->write('log', 'today', 'today'); // creating today's file prunes the oldest beyond 3

        $left = array_map('basename', glob("$dir/nesto*.log") ?: []);
        sort($left);
        $expected = ['nesto-' . date('Y-m-d') . '.log', 'nesto-2026-01-03.log', 'nesto-2026-01-04.log', 'nesto.log'];
        sort($expected);
        self::assertSame($expected, $left);
        self::assertSame("today\n", file_get_contents($channel->currentPath()));
        $channel->close();
    }

    public function testStackWritesToEveryChannelAndNullWritesNothing(): void
    {
        $a = fopen('php://memory', 'w+b');
        $b = fopen('php://memory', 'w+b');
        Logger::useChannel(new StackChannel([new StreamChannel($a), new StreamChannel($b)]));
        (new Logger('Stack'))->warning('twice');

        foreach ([$a, $b] as $stream) {
            rewind($stream);
            self::assertStringContainsString('WARN [Stack] twice', (string) stream_get_contents($stream));
        }

        $channel = LogConfig::channel(['default' => 'null']);
        self::assertInstanceOf(StackChannel::class, $channel);
        self::assertSame([], $channel->channels());
    }

    public function testEnvironmentPicksChannelPathAndLevel(): void
    {
        $this->sandbox();
        $this->setEnv(['LOG_CHANNEL' => 'file', 'LOG_PATH' => 'var/log/app.log', 'LOG_LEVEL' => 'warning']);

        LogConfig::apply(LogConfig::load());
        $logger = new Logger('Env');
        $logger->debug('hidden debug');
        $logger->info('hidden info');
        $logger->warning('shown warning');
        $logger->error('shown error', ['exception' => new \LogicException('why')]);

        $log = (string) file_get_contents($this->sandbox() . '/var/log/app.log');
        self::assertStringNotContainsString('hidden', $log);
        self::assertStringContainsString('WARN [Env] shown warning', $log);
        self::assertStringContainsString('ERROR [Env] shown error', $log);
        self::assertStringContainsString("LogicException: why\n#0 ", $log, 'stack trace lines follow the entry');
        self::assertStringNotContainsString("\033[", $log, 'no colours in files');
    }

    public function testLevelDefaultsToDebugLocallyAndInfoInProduction(): void
    {
        $this->sandbox();
        $this->setEnv(['APP_ENV' => 'production', 'LOG_LEVEL' => null]);
        self::assertSame('info', LogConfig::load()['level']);
        $this->setEnv(['APP_ENV' => 'local']);
        self::assertSame('debug', LogConfig::load()['level']);
    }

    public function testConfigFileAndDailyRotationFromEnv(): void
    {
        $this->sandboxFile('config/logging.php', "<?php\nreturn ['default' => 'stack', 'channels' => ['stack' => ['driver' => 'stack', 'channels' => 'file'], 'file' => ['driver' => 'file', 'path' => env('LOG_PATH'), 'daily' => env('LOG_DAILY', false)]]];\n");
        $this->setEnv(['LOG_PATH' => $this->sandbox() . '/logs/nesto.log', 'LOG_DAILY' => 'true', 'LOG_CHANNEL' => 'stdout']);

        $config = LogConfig::load();
        self::assertSame('stack', $config['default'], 'config/logging.php wins over the built-in default');
        LogConfig::apply($config);
        (new Logger('Daily'))->info('rotated');

        self::assertFileExists($this->sandbox() . '/logs/nesto-' . date('Y-m-d') . '.log');
        self::assertStringContainsString('stack (file logs/nesto-' . date('Y-m-d') . '.log (daily, 14 kept))', LogConfig::describe($config));
    }

    public function testLoggerModuleWithoutArgumentsAppliesTheConfigurationAndLogsHttpErrors(): void
    {
        $this->sandbox();
        $this->setEnv(['LOG_CHANNEL' => 'file', 'LOG_PATH' => 'storage/logs/nesto.log', 'LOG_HTTP_ERRORS' => null]);
        $app = NestoFactory::create(ConfiguredLoggingModule::class);
        $log = fn () => (string) @file_get_contents($this->sandbox() . '/storage/logs/nesto.log');

        self::assertSame(500, $app->handle(Request::create('GET', '/errors/boom'))->getStatusCode());
        self::assertSame(404, $app->handle(Request::create('GET', '/errors/missing'))->getStatusCode());
        self::assertSame(503, $app->handle(Request::create('GET', '/errors/down'))->getStatusCode());
        self::assertStringContainsString('ERROR [ExceptionsHandler] kaboom', $log());
        self::assertStringContainsString('RuntimeException: kaboom', $log());
        self::assertStringContainsString('ERROR [ExceptionsHandler] GET /errors/down 503 maintenance', $log());
        self::assertStringNotContainsString('no cat', $log(), '4xx are not logged by default (5xx)');
        self::assertInstanceOf(Logger::class, $app->get(LoggerInterface::class));
        self::assertSame('file', $app->get(LoggerModule::CONFIG)['default']);

        $this->setEnv(['LOG_HTTP_ERRORS' => '4xx']);
        LogConfig::reset();
        $app->handle(Request::create('GET', '/errors/missing'));
        $app->handle(Request::create('GET', '/errors/bad'));
        self::assertStringContainsString('LOG [ExceptionsHandler] GET /errors/missing 404 no cat', $log(), '404 at notice level');
        self::assertStringContainsString('WARN [ExceptionsHandler] GET /errors/bad 400 bad cat', $log());

        $this->setEnv(['LOG_HTTP_ERRORS' => 'none']);
        $before = $log();
        $app->handle(Request::create('GET', '/errors/boom'));
        self::assertSame($before, $log(), 'none: nothing logged');
    }

    public function testExplicitArgumentsWinOverTheEnvironment(): void
    {
        $this->sandbox();
        $this->setEnv(['LOG_CHANNEL' => 'stderr', 'LOG_LEVEL' => 'debug']);
        $module = new LoggerModule(channel: 'file', path: 'custom/app.log', level: 'error');
        $module->toDynamicModule();

        self::assertInstanceOf(FileChannel::class, Logger::channel());
        self::assertSame($this->sandbox() . '/custom/app.log', Logger::channel()->path());
        self::assertSame('error', Logger::minLevel());

        (new LoggerModule(configure: false, channel: 'stdout'))->toDynamicModule();
        self::assertInstanceOf(FileChannel::class, Logger::channel(), 'configure: false leaves the output alone');
    }

    public function testWithoutConfigurationTheConsoleSplitIsKept(): void
    {
        $this->sandbox();
        $this->setEnv(['LOG_CHANNEL' => null, 'LOG_LEVEL' => null, 'APP_ENV' => null]);
        $config = LogConfig::load();
        $channel = LogConfig::channel($config);

        self::assertInstanceOf(StreamChannel::class, $channel);
        self::assertSame('console', $channel->target());
        self::assertSame('debug', $config['level']);
    }

    public function testUnknownChannelIsReported(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown channel "papertrail"');
        LogConfig::channel(['default' => 'papertrail']);
    }
}
