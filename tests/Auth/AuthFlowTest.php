<?php

declare(strict_types=1);

namespace Nesto\Tests\Auth;

use Nesto\Auth\StrategyRegistry;
use Nesto\Core\NestoApplication;
use Nesto\Core\NestoFactory;
use Nesto\Http\Request;
use Nesto\Jwt\JwtService;
use Nesto\Tests\Auth\Fixtures\AppJwtStrategy;
use Nesto\Tests\Auth\Fixtures\AppModule;
use PHPUnit\Framework\TestCase;

final class AuthFlowTest extends TestCase
{
    private NestoApplication $app;

    protected function setUp(): void
    {
        $this->app = NestoFactory::create(AppModule::class)->init();
    }

    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    public function testRegistryDiscoversStrategies(): void
    {
        $registry = $this->app->get(StrategyRegistry::class);
        self::assertSame(['jwt'], $registry->names());
        self::assertInstanceOf(AppJwtStrategy::class, $registry->get('jwt'));
        self::assertSame('jwt', $registry->defaultStrategy());
        self::assertSame($registry, StrategyRegistry::current());
    }

    public function testLoginThenProfile(): void
    {
        $login = $this->app->handle(Request::create('POST', '/auth/login', body: ['username' => 'alice', 'password' => 'secret']));
        self::assertSame(201, $login->getStatusCode());
        $token = $this->json($login)['access_token'];
        self::assertSame(1, $this->app->get(JwtService::class)->verify($token)['sub']);

        $bad = $this->app->handle(Request::create('POST', '/auth/login', body: ['username' => 'alice', 'password' => 'wrong']));
        self::assertSame(401, $bad->getStatusCode());

        $anonymous = $this->app->handle(Request::create('GET', '/profile'));
        self::assertSame(401, $anonymous->getStatusCode());
        self::assertSame('No auth token', $this->json($anonymous)['message']);

        $profile = $this->app->handle(Request::create('GET', '/profile', headers: ['Authorization' => "Bearer $token"]));
        self::assertSame(200, $profile->getStatusCode());
        self::assertSame(['id' => 1, 'username' => 'alice', 'roles' => ['admin']], $this->json($profile));

        $tampered = substr($token, 0, -3) . 'abc';
        $rejected = $this->app->handle(Request::create('GET', '/profile', headers: ['Authorization' => "Bearer $tampered"]));
        self::assertSame(401, $rejected->getStatusCode());
        self::assertSame('invalid signature', $this->json($rejected)['message']);

        // token for a user that no longer exists → validate() returns null → 401
        $ghost = $this->app->get(JwtService::class)->sign(['sub' => 999]);
        self::assertSame(401, $this->app->handle(Request::create('GET', '/profile', headers: ['Authorization' => "Bearer $ghost"]))->getStatusCode());
    }

    public function testSubclassGuardAndCurrentUserProperty(): void
    {
        $token = $this->app->get(JwtService::class)->sign(['sub' => 2]);
        $res = $this->app->handle(Request::create('GET', '/profile/id', headers: ['Authorization' => "Bearer $token"]));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame(['id' => 2], $this->json($res));
        self::assertSame(401, $this->app->handle(Request::create('GET', '/profile/id'))->getStatusCode());
    }

    public function testRolesGuard(): void
    {
        $jwt = $this->app->get(JwtService::class);
        $admin = $jwt->sign(['sub' => 1]);
        $user = $jwt->sign(['sub' => 2]);

        $ok = $this->app->handle(Request::create('GET', '/admin', headers: ['Authorization' => "Bearer $admin"]));
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame(['admin' => 'alice'], $this->json($ok));

        $forbidden = $this->app->handle(Request::create('GET', '/admin', headers: ['Authorization' => "Bearer $user"]));
        self::assertSame(403, $forbidden->getStatusCode());

        self::assertSame(401, $this->app->handle(Request::create('GET', '/admin'))->getStatusCode());
    }

    public function testIsPublicLetsAnonymousThroughButStillResolvesUser(): void
    {
        $anon = $this->app->handle(Request::create('GET', '/optional'));
        self::assertSame(200, $anon->getStatusCode());
        self::assertSame(['user' => null], $this->json($anon));

        $token = $this->app->get(JwtService::class)->sign(['sub' => 2]);
        $known = $this->app->handle(Request::create('GET', '/optional', headers: ['Authorization' => "Bearer $token"]));
        self::assertSame(['user' => 'bob'], $this->json($known));
    }
}
