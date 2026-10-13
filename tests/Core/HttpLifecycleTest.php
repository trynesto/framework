<?php

declare(strict_types=1);

namespace Nesto\Tests\Core\HttpLifecycleFixtures;

use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Get;
use Nesto\Common\Attributes\Module;
use Nesto\Common\Attributes\Req;
use Nesto\Common\Attributes\UseFilters;
use Nesto\Common\Contracts\ExceptionFilter;
use Nesto\Common\Contracts\Middleware;
use Nesto\Common\Contracts\NestoModule;
use Nesto\Common\Exceptions\BadRequestException;
use Nesto\Common\Exceptions\ForbiddenException;
use Nesto\Core\ArgumentsHost;
use Nesto\Core\Constants;
use Nesto\Core\MiddlewareConsumer;
use Nesto\Http\Request;
use Nesto\Http\Response;
use Psr\Http\Message\ResponseInterface;

final class CatchAllFilter implements ExceptionFilter
{
    public function catch(\Throwable $exception, ArgumentsHost $host): ResponseInterface
    {
        return Response::json(['filtered' => true], 299);
    }
}

#[Controller('cats')]
#[UseFilters(CatchAllFilter::class)]
final class CatsController
{
    #[Get]
    public function list(): array
    {
        return ['cats'];
    }

    #[Get('boom')]
    public function boom(): never
    {
        throw new \RuntimeException('boom');
    }

    #[Get('ok')]
    public function ok(): array
    {
        return ['ok' => true];
    }
}

#[Controller('dogs')]
final class DogsController
{
    #[Get('crash')]
    public function crash(): never
    {
        throw new \RuntimeException('kaboom');
    }

    #[Get('forbidden')]
    public function forbidden(): never
    {
        throw new ForbiddenException('nope');
    }
}

#[Controller(path: 'dash', host: 'admin.example.com')]
final class AdminDashController
{
    #[Get]
    public function index(): array
    {
        return ['dash' => 'admin'];
    }
}

#[Controller(path: 'dash', host: ':account.example.com')]
final class AccountDashController
{
    #[Get]
    public function index(#[Req] Request $request): array
    {
        return ['dash' => 'account', 'hosts' => $request->attribute(Constants::HOST_PARAMS)];
    }
}

#[Controller('dash')]
final class PublicDashController
{
    #[Get]
    public function index(): array
    {
        return ['dash' => 'public'];
    }
}

#[Controller(path: 'only', host: 'admin.example.com')]
final class AdminOnlyController
{
    #[Get]
    public function index(): array
    {
        return ['only' => 'admin'];
    }
}

final class TagMiddleware implements Middleware
{
    public function use(Request $request, \Closure $next): ResponseInterface
    {
        return $next($request)->withHeader('X-Tag', 'yes');
    }
}

final class FailingMiddleware implements Middleware
{
    public function use(Request $request, \Closure $next): ResponseInterface
    {
        if ($request->header('X-Fail') !== null) {
            throw new BadRequestException('middleware failed');
        }

        return $next($request);
    }
}

final class TraceMiddleware implements Middleware
{
    /** @var list<array{0: string, 1: int}> */
    public static array $log = [];

    public function use(Request $request, \Closure $next): ResponseInterface
    {
        $response = $next($request);
        self::$log[] = [$request->path(), $response->getStatusCode()];

        return $response->withHeader('X-Trace', 'after');
    }
}

#[Module(controllers: [CatsController::class, DogsController::class, AdminDashController::class, AccountDashController::class, PublicDashController::class, AdminOnlyController::class])]
final class LifecycleModule implements NestoModule
{
    public function configure(MiddlewareConsumer $consumer): void
    {
        $consumer->apply(TraceMiddleware::class)->forRoutes('*');
        $consumer->apply(TagMiddleware::class)->exclude('cats/boom')->forRoutes('cats', 'cats/*');
        $consumer->apply(FailingMiddleware::class)->forRoutes('cats/*');
    }
}

namespace Nesto\Tests\Core;

use Nesto\Core\NestoApplication;
use Nesto\Core\NestoFactory;
use Nesto\Http\Request;
use Nesto\Logger\Logger;
use Nesto\Tests\Core\HttpLifecycleFixtures\LifecycleModule;
use Nesto\Tests\Core\HttpLifecycleFixtures\TraceMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/** Middleware path matching, filter scopes, 404s through middleware, error logging and host routing. */
final class HttpLifecycleTest extends TestCase
{
    private NestoApplication $app;
    /** @var resource */
    private $log;

    protected function setUp(): void
    {
        TraceMiddleware::$log = [];
        $this->log = fopen('php://memory', 'w+b');
        Logger::toStream($this->log);
        $this->app = NestoFactory::create(LifecycleModule::class)
            ->setGlobalPrefix('api')
            ->enableVersioning(['defaultVersion' => '1']);
    }

    protected function tearDown(): void
    {
        Logger::toStream(null);
    }

    private function get(string $uri, array $headers = []): ResponseInterface
    {
        return $this->app->handle(Request::create('GET', $uri, headers: $headers));
    }

    private function logged(): string
    {
        rewind($this->log);

        return (string) stream_get_contents($this->log);
    }

    public function test_path_middleware_ignores_global_prefix_and_version(): void
    {
        self::assertSame('yes', $this->get('/api/v1/cats')->getHeaderLine('X-Tag'));
        self::assertSame('yes', $this->get('/api/v1/cats/ok')->getHeaderLine('X-Tag'));
        self::assertSame('', $this->get('/api/v1/cats/boom')->getHeaderLine('X-Tag'), 'exclude() too');
        self::assertSame('', $this->get('/api/v1/dogs/forbidden')->getHeaderLine('X-Tag'));
    }

    public function test_route_filters_do_not_catch_middleware_errors(): void
    {
        $r = $this->get('/api/v1/cats/boom');
        self::assertSame(299, $r->getStatusCode(), 'the controller filter catches handler errors');

        $r = $this->get('/api/v1/cats/ok', ['X-Fail' => '1']);
        self::assertSame(400, $r->getStatusCode(), 'middleware errors only reach global filters');
        self::assertSame('middleware failed', json_decode((string) $r->getBody(), true)['message']);
    }

    public function test_middleware_runs_fully_for_unmatched_routes(): void
    {
        $r = $this->get('/api/v1/nope');
        self::assertSame(404, $r->getStatusCode());
        self::assertSame('after', $r->getHeaderLine('X-Trace'));
        self::assertSame([['/api/v1/nope', 404]], TraceMiddleware::$log);
    }

    public function test_unhandled_errors_are_logged_with_the_stack_trace(): void
    {
        self::assertSame(500, $this->get('/api/v1/dogs/crash')->getStatusCode());
        $log = $this->logged();
        self::assertStringContainsString('[ExceptionsHandler] kaboom', $log);
        self::assertStringContainsString('RuntimeException: kaboom', $log);
        self::assertStringContainsString('#0 ', $log);

        ftruncate($this->log, 0);
        rewind($this->log);
        self::assertSame(403, $this->get('/api/v1/dogs/forbidden')->getStatusCode());
        self::assertSame(404, $this->get('/api/v1/missing')->getStatusCode());
        self::assertSame('', $this->logged(), 'HttpExceptions are not logged');
    }

    public function test_controller_host_restricts_routes_and_captures_params(): void
    {
        $body = fn (ResponseInterface $r) => json_decode((string) $r->getBody(), true);

        self::assertSame(['dash' => 'admin'], $body($this->get('http://admin.example.com/api/v1/dash')));
        self::assertSame(['dash' => 'account', 'hosts' => ['account' => 'acme']], $body($this->get('http://acme.example.com:8080/api/v1/dash')));
        self::assertSame(['dash' => 'public'], $body($this->get('http://localhost/api/v1/dash')));

        self::assertSame(['only' => 'admin'], $body($this->get('http://ADMIN.example.com/api/v1/only')));
        self::assertSame(404, $this->get('http://other.test/api/v1/only')->getStatusCode());
    }
}
