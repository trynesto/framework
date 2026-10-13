<?php

declare(strict_types=1);

namespace Nesto\Tests\Logger;

use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Get;
use Nesto\Common\Attributes\Module;
use Nesto\Core\NestoFactory;
use Nesto\Http\Request;
use Nesto\Logger\Logger;
use Nesto\Logger\LoggerModule;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

#[Controller('log')]
final class LogController
{
    public function __construct(private readonly LoggerInterface $logger, private readonly Logger $concrete)
    {
    }

    #[Get()]
    public function index(): array
    {
        $this->logger->info('hit');

        return ['same' => $this->logger === $this->concrete, 'context' => $this->concrete->getContext()];
    }
}

#[Module(imports: [new LoggerModule(context: 'App')], controllers: [LogController::class])]
final class LoggerAppModule
{
}

final class LoggerTest extends TestCase
{
    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        $this->stream = fopen('php://memory', 'w+');
        Logger::toStream($this->stream);
        Logger::useColors(false);
    }

    protected function tearDown(): void
    {
        Logger::toStream(null);
        Logger::useColors(null);
        Logger::overrideLogger(null);
        Logger::setDefaultLogLevels(null);
        Logger::setAppName('Nesto');
    }

    private function captured(): string
    {
        rewind($this->stream);

        return stream_get_contents($this->stream) ?: '';
    }

    public function testWritesNestStyleLine(): void
    {
        (new Logger('CatsService'))->info('Cat created');

        self::assertMatchesRegularExpression(
            '#^\[Nesto\] \d+  - \d{1,2}/\d{1,2}/\d{4}, \d{1,2}:\d{2}:\d{2} [AP]M     LOG \[CatsService\] Cat created$#m',
            $this->captured(),
        );
    }

    public function testLevelsAndAliases(): void
    {
        $logger = new Logger('X');
        $logger->error('e');
        $logger->warn('w');
        $logger->debug('d');
        $logger->verbose('v');
        $logger->fatal('f');
        $out = $this->captured();

        self::assertStringContainsString('  ERROR [X] e', $out);
        self::assertStringContainsString('   WARN [X] w', $out);
        self::assertStringContainsString('  DEBUG [X] d', $out);
        self::assertStringContainsString('VERBOSE [X] v', $out);
        self::assertStringContainsString('  FATAL [X] f', $out);
    }

    public function testSetLogLevelsFilters(): void
    {
        $logger = (new Logger('X'))->setLogLevels(['error']);
        $logger->info('hidden');
        $logger->error('shown');
        $out = $this->captured();

        self::assertStringNotContainsString('hidden', $out);
        self::assertStringContainsString('shown', $out);

        Logger::setDefaultLogLevels(['warn']);
        $other = new Logger('Y');
        $other->debug('nope');
        $other->warning('yes');
        $out = $this->captured();
        self::assertStringNotContainsString('nope', $out);
        self::assertStringContainsString('yes', $out);
    }

    public function testInterpolationAndException(): void
    {
        (new Logger)->error('Failed for {user}', ['user' => 'bob', 'exception' => new \RuntimeException('boom')]);
        $out = $this->captured();

        self::assertStringContainsString('ERROR Failed for bob', $out);
        self::assertStringContainsString('RuntimeException: boom', $out);
    }

    public function testOverrideLoggerForwardsEverything(): void
    {
        $spy = new class extends AbstractLogger {
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
        Logger::overrideLogger($spy);
        (new Logger('Svc'))->info('hello');
        (new Logger('Svc'))->verbose('v');

        self::assertSame('', $this->captured());
        self::assertSame(['info', 'hello', ['context' => 'Svc']], $spy->records[0]);
        self::assertSame('debug', $spy->records[1][0]);
    }

    public function testColorsWhenForced(): void
    {
        Logger::useColors(true);
        (new Logger('C'))->info('colored');

        self::assertStringContainsString("\033[32m", $this->captured());
    }

    public function testToFile(): void
    {
        $path = sys_get_temp_dir() . '/nesto-logger-test-' . uniqid() . '/app.log';
        Logger::toFile($path);
        (new Logger('File'))->info('to disk');
        Logger::toStream($this->stream);

        self::assertFileExists($path);
        self::assertStringContainsString('LOG [File] to disk', (string) file_get_contents($path));
        unlink($path);
        rmdir(dirname($path));
    }

    public function testLoggerModuleProvidesPsrInterface(): void
    {
        $app = NestoFactory::create(LoggerAppModule::class);
        $response = $app->handle(Request::create('GET', '/log'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['same' => true, 'context' => 'App'], json_decode((string) $response->getBody(), true));
        self::assertStringContainsString('LOG [App] hit', $this->captured());
    }

    public function testRegisterOnContainer(): void
    {
        $app = NestoFactory::create(LoggerAppModule::class);
        $logger = Logger::register($app->container(), new Logger('Core'));

        self::assertSame($logger, $app->get(LoggerInterface::class));
    }
}
