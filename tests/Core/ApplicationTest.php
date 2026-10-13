<?php

declare(strict_types=1);

namespace Nesto\Tests\Core;

require_once __DIR__ . "/Fixtures.php";

use Nesto\Core\NestoApplication;
use Nesto\Core\NestoFactory;
use Nesto\Core\UnknownDependencyException;
use Nesto\Http\Request;
use Nesto\Logger\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class ApplicationTest extends TestCase
{
    private NestoApplication $app;

    protected function setUp(): void
    {
        CatsService::$hooks = [];
        WrapInterceptor::$log = [];
        LoggerMiddleware::$seen = [];
        RequestCounter::$created = 0;
        TransientThing::$created = 0;
        Logger::toStream(fopen('php://memory', 'w+b')); // 500s are logged (ExceptionsHandler): keep the test output clean
        $this->app = NestoFactory::create(AppModule::class)->setGlobalPrefix('api')->enableVersioning(['prefix' => 'v']);
        $this->app->init();
    }

    protected function tearDown(): void
    {
        Logger::toStream(null);
    }

    private function json(ResponseInterface $r): mixed
    {
        return json_decode((string) $r->getBody(), true);
    }

    private function get(string $uri, array $headers = []): ResponseInterface
    {
        return $this->app->handle(Request::create('GET', $uri, headers: $headers));
    }

    public function test_lifecycle_hooks_run_in_order(): void
    {
        $this->assertSame(['init', 'bootstrap'], CatsService::$hooks);
        $this->app->close();
        $this->assertSame(['init', 'bootstrap', 'destroy'], CatsService::$hooks);
    }

    public function test_di_value_factory_existing_and_global_module(): void
    {
        $this->assertSame('Nesto', $this->app->get('APP_NAME'));
        $this->assertSame('sqlite:Nesto', $this->app->get('DB')['dsn']);
        $this->assertSame('Nesto', $this->app->get('INVOKED')['from']);
        $this->assertSame($this->app->get(CatsService::class), $this->app->get('ALIAS'));
        $this->assertSame('hello', $this->app->get(Greeter::class)->greeting);
    }

    public function test_module_encapsulation_is_enforced(): void
    {
        $this->expectException(UnknownDependencyException::class);
        $this->expectExceptionMessageMatches('/GREETING/');
        NestoFactory::create(BrokenAppModule::class)->init();
    }

    public function test_get_with_interceptor_and_query_coercion(): void
    {
        $r = $this->get('/api/cats?limit=5&q=tom');
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame(['data' => ['limit' => 5, 'q' => 'tom', 'app' => 'Nesto', 'cats' => [['id' => 1, 'name' => 'Tom']]], 'wrapped' => true], $this->json($r));
        $this->assertSame(['before:index', 'after:index'], WrapInterceptor::$log);
        $this->assertSame('application/json; charset=utf-8', $r->getHeaderLine('Content-Type'));
    }

    public function test_param_pipe_and_bad_param(): void
    {
        $this->assertSame(['id' => 42], $this->json($this->get('/api/cats/42')));
        $r = $this->get('/api/cats/abc');
        $this->assertSame(400, $r->getStatusCode());
        $this->assertSame('Bad Request', $this->json($r)['error']);
    }

    public function test_enum_param(): void
    {
        $this->assertSame(['breed' => 'persian'], $this->json($this->get('/api/cats/breed/persian')));
        $this->assertSame(400, $this->get('/api/cats/breed/dog')->getStatusCode());
    }

    public function test_guard_with_metadata(): void
    {
        $body = ['name' => 'Felix', 'age' => 3];
        $r = $this->app->handle(Request::create('POST', '/api/cats', body: $body));
        $this->assertSame(403, $r->getStatusCode());
        $this->assertSame(['statusCode' => 403, 'message' => 'Forbidden resource', 'error' => 'Forbidden'], $this->json($r));

        $r = $this->app->handle(Request::create('POST', '/api/cats', body: $body, headers: ['X-Role' => 'admin']));
        $this->assertSame(201, $r->getStatusCode(), 'POST defaults to 201 like Nest');
        $this->assertSame($body, $this->json($r));
    }

    public function test_method_pipes_apply_to_all_params(): void
    {
        $r = $this->app->handle(Request::create('PUT', '/api/cats/7', body: ['name' => 'felix']));
        $this->assertSame(['id' => 7, 'name' => 'FELIX'], $this->json($r));
    }

    public function test_custom_param_decorator_headers_and_middleware(): void
    {
        $r = $this->get('/api/cats/me', ['X-User' => 'hussein']);
        $this->assertSame(['user' => 'hussein', 'mw' => 'yes', 'header' => 'hussein'], $this->json($r));
        $this->assertSame('logger', $r->getHeaderLine('X-Middleware'));
        $this->assertSame(['/api/cats/me'], LoggerMiddleware::$seen);
    }

    public function test_middleware_exclude(): void
    {
        $r = $this->get('/api/cats/text');
        $this->assertSame('plain', (string) $r->getBody());
        $this->assertSame('text', $r->getHeaderLine('X-Kind'));
        $this->assertSame('', $r->getHeaderLine('X-Middleware'));
    }

    public function test_res_builder_http_code_and_redirect(): void
    {
        $r = $this->get('/api/cats/res');
        $this->assertSame(218, $r->getStatusCode());
        $this->assertSame('yes', $r->getHeaderLine('X-Custom'));

        $r = $this->get('/api/cats/nothing');
        $this->assertSame(204, $r->getStatusCode());
        $this->assertSame('', (string) $r->getBody());

        $r = $this->get('/api/cats/go');
        $this->assertSame(301, $r->getStatusCode());
        $this->assertSame('https://example.com', $r->getHeaderLine('Location'));
    }

    public function test_exception_filters(): void
    {
        $r = $this->get('/api/cats/boom');
        $this->assertSame(404, $r->getStatusCode());
        $this->assertSame(['custom' => true, 'path' => '/api/cats/boom'], $this->json($r));

        $r = $this->get('/api/cats/crash');
        $this->assertSame(500, $r->getStatusCode());
        $this->assertSame(['statusCode' => 500, 'message' => 'Internal server error'], $this->json($r), 'no leak without debug');

        $r = $this->get('/api/cats/custom-error');
        $this->assertSame(418, $r->getStatusCode());
        $this->assertSame('Custom', $this->json($r)['error']);
    }

    public function test_debug_mode_exposes_exception(): void
    {
        $app = NestoFactory::create(AppModule::class, ['debug' => true])->setGlobalPrefix('api');
        $r = $app->handle(Request::create('GET', '/api/cats/crash'));
        $this->assertSame('secret', $this->json($r)['message']);
        $this->assertSame(\RuntimeException::class, $this->json($r)['exception']);
    }

    public function test_debug_defaults_to_the_app_debug_env_var_read_lazily(): void
    {
        $app = NestoFactory::create(AppModule::class)->setGlobalPrefix('api');
        $_ENV['APP_DEBUG'] = 'true';
        try {
            $r = $app->handle(Request::create('GET', '/api/cats/crash'));
            $this->assertSame('secret', $this->json($r)['message']);
        } finally {
            unset($_ENV['APP_DEBUG']);
        }
        $r = $app->handle(Request::create('GET', '/api/cats/crash'));
        $this->assertSame('Internal server error', $this->json($r)['message']);
        $r = NestoFactory::create(AppModule::class, ['debug' => false])->setGlobalPrefix('api')->handle(Request::create('GET', '/api/cats/crash'));
        $this->assertSame('Internal server error', $this->json($r)['message']);
    }

    public function test_404_and_405(): void
    {
        $r = $this->get('/api/dogs');
        $this->assertSame(404, $r->getStatusCode());
        $this->assertSame('Cannot GET /api/dogs', $this->json($r)['message']);

        $r = $this->app->handle(Request::create('PATCH', '/api/cats'));
        $this->assertSame(405, $r->getStatusCode());
    }

    public function test_request_scope_and_bubbling(): void
    {
        $a = $this->json($this->get('/api/cats/scoped'));
        $b = $this->json($this->get('/api/cats/scoped'));
        $this->assertTrue($a['same'], 'same request-scoped instance inside one request');
        $this->assertSame($a['viaService'], $a['direct']);
        $this->assertNotSame($a['direct'], $b['direct'], 'new instance per request');
    }

    public function test_transient_scope(): void
    {
        $this->app->get(TransientThing::class);
        $this->app->get(TransientThing::class);
        $this->assertSame(2, TransientThing::$created);
    }

    public function test_uri_versioning(): void
    {
        $this->assertSame(['v' => 1], $this->json($this->get('/api/v1/v/ping')));
        $this->assertSame(['v' => 2], $this->json($this->get('/api/v2/v/ping')));
    }

    public function test_cors_preflight(): void
    {
        $app = NestoFactory::create(AppModule::class)->setGlobalPrefix('api')->enableCors(['origin' => 'https://app.test', 'credentials' => true]);
        $r = $app->handle(Request::create('OPTIONS', '/api/cats', headers: ['Origin' => 'https://app.test', 'Access-Control-Request-Method' => 'POST']));
        $this->assertSame(204, $r->getStatusCode());
        $this->assertSame('https://app.test', $r->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('true', $r->getHeaderLine('Access-Control-Allow-Credentials'));

        $r = $app->handle(Request::create('GET', '/api/cats/1', headers: ['Origin' => 'https://evil.test']));
        $this->assertSame('', $r->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function test_route_listing(): void
    {
        $ids = array_map(fn ($r) => $r->id(), $this->app->router()->routes());
        $this->assertContains('GET /api/cats', $ids);
        $this->assertContains('DELETE /api/cats/{id}', $ids);
        $this->assertContains('GET /api/v2/v/ping', $ids);
    }

    public function test_global_enhancers_via_app(): void
    {
        $app = NestoFactory::create(AppModule::class)->setGlobalPrefix('api')->useGlobalInterceptors(WrapInterceptor::class);
        $r = $app->handle(Request::create('GET', '/api/cats/5'));
        $this->assertSame(['data' => ['id' => 5], 'wrapped' => true], $this->json($r));
    }
}
