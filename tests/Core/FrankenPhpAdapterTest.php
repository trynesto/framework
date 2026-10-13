<?php

declare(strict_types=1);

namespace {
    // Stand-in for the FrankenPHP SAPI function: replays the queued requests through the worker handler.
    if (! function_exists('frankenphp_handle_request')) {
        function frankenphp_handle_request(callable $handler): bool
        {
            $request = array_shift(\Nesto\Tests\Core\FrankenPhpAdapterTest::$queue);
            if ($request === null) {
                return false;
            }
            $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $request, 'SERVER_PROTOCOL' => 'HTTP/1.1', 'HTTP_HOST' => 'localhost', 'FRANKENPHP_WORKER' => '1'];
            $_GET = [];
            ob_start();
            $handler();
            \Nesto\Tests\Core\FrankenPhpAdapterTest::$responses[] = ob_get_clean();

            return \Nesto\Tests\Core\FrankenPhpAdapterTest::$queue !== [];
        }
    }
}

namespace Nesto\Tests\Core {
    use Nesto\Common\Attributes\Controller;
    use Nesto\Common\Attributes\Get;
    use Nesto\Common\Attributes\Injectable;
    use Nesto\Common\Attributes\Module;
    use Nesto\Core\NestoFactory;
    use Nesto\Core\Platform\BootErrorHandler;
    use Nesto\Core\Platform\FrankenPhpAdapter;
    use Nesto\Logger\Logger;
    use PHPUnit\Framework\TestCase;

    #[Injectable]
    final class WorkerHits
    {
        public static int $booted = 0;
        public int $hits = 0;

        public function __construct()
        {
            self::$booted++;
        }
    }

    #[Controller]
    final class WorkerController
    {
        public function __construct(private readonly WorkerHits $hits) {}

        #[Get('hits')]
        public function hits(): array
        {
            return ['hits' => ++$this->hits->hits];
        }
    }

    #[Module(controllers: [WorkerController::class], providers: [WorkerHits::class])]
    final class WorkerModule {}

    final class FrankenPhpAdapterTest extends TestCase
    {
        /** @var list<string> */
        public static array $queue = [];

        /** @var list<string> */
        public static array $responses = [];

        private array $server;

        protected function setUp(): void
        {
            $this->server = $_SERVER;
            self::$queue = [];
            self::$responses = [];
            WorkerHits::$booted = 0;
        }

        protected function tearDown(): void
        {
            $_SERVER = $this->server;
        }

        public function testNotAWorkerWithoutTheFrankenPhpWorkerFlag(): void
        {
            unset($_SERVER['FRANKENPHP_WORKER']);
            self::assertFalse(FrankenPhpAdapter::isWorker()); // the function alone means classic mode
        }

        public function testListenRunsTheWorkerLoopWithTheAppKeptInMemory(): void
        {
            $_SERVER['FRANKENPHP_WORKER'] = '1';
            self::$queue = ['/hits', '/hits', '/hits'];

            NestoFactory::create(WorkerModule::class)->listen();

            self::assertSame(['{"hits":1}', '{"hits":2}', '{"hits":3}'], self::$responses);
            self::assertSame(1, WorkerHits::$booted);
        }

        public function testABootErrorKeepsTheWorkerUpAndAnswersJson500(): void
        {
            require_once __DIR__ . '/Fixtures.php';
            $_SERVER['FRANKENPHP_WORKER'] = '1';
            self::$queue = ['/hits', '/hits'];
            Logger::toStream($log = fopen('php://memory', 'w+b'));
            try {
                NestoFactory::create(BrokenAppModule::class, ['debug' => false])->listen();
            } finally {
                Logger::toStream(null);
            }

            $expected = '{"statusCode":500,"message":"Internal server error","error":"Internal Server Error"}';
            self::assertSame([$expected, $expected], self::$responses, 'no crash: every request gets the JSON 500');
            rewind($log);
            $logged = (string) stream_get_contents($log);
            self::assertStringContainsString('[ExceptionHandler]', $logged);
            self::assertStringContainsString('UnknownDependencyException', $logged);
            self::assertSame(1, substr_count($logged, '[ExceptionHandler]'), 'logged once, at boot');
        }

        public function testABootErrorOutsideAWorkerIsAJson500(): void
        {
            require_once __DIR__ . '/Fixtures.php';
            unset($_SERVER['FRANKENPHP_WORKER']);
            $_SERVER += ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
            Logger::toStream($log = fopen('php://memory', 'w+b'));
            ob_start();
            try {
                NestoFactory::create(BrokenAppModule::class, ['debug' => true])->listen();
            } finally {
                $output = (string) ob_get_clean();
                Logger::toStream(null);
            }

            $body = json_decode($output, true);
            self::assertSame(500, $body['statusCode']);
            self::assertSame('Internal Server Error', $body['error']);
            self::assertStringContainsString('GREETING', $body['message'], 'debug: the real message');
            rewind($log);
            self::assertStringContainsString('[ExceptionHandler]', (string) stream_get_contents($log));
        }

        public function testBootErrorResponseHidesTheMessageWithoutDebug(): void
        {
            $r = BootErrorHandler::response(new \RuntimeException('secret'), false);
            self::assertSame(500, $r->getStatusCode());
            self::assertSame(['statusCode' => 500, 'message' => 'Internal server error', 'error' => 'Internal Server Error'], json_decode((string) $r->getBody(), true));
            self::assertSame('secret', json_decode((string) BootErrorHandler::response(new \RuntimeException('secret'), true)->getBody(), true)['message']);
        }

        public function testMaxRequestsEndsTheWorker(): void
        {
            $_SERVER['FRANKENPHP_WORKER'] = '1';
            self::$queue = ['/hits', '/hits', '/hits', '/hits'];

            FrankenPhpAdapter::serve(NestoFactory::create(WorkerModule::class), maxRequests: 2);

            self::assertCount(2, self::$responses);
            self::assertCount(2, self::$queue, 'the remaining requests go to a fresh worker');
        }
    }
}
