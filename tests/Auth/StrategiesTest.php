<?php

declare(strict_types=1);

namespace Nesto\Tests\Auth;

use Nesto\Auth\Attributes\Roles;
use Nesto\Auth\AuthGuard;
use Nesto\Auth\ExtractJwt;
use Nesto\Auth\Hash;
use Nesto\Auth\RolesGuard;
use Nesto\Auth\Strategies\ApiKeyStrategy;
use Nesto\Auth\Strategies\BearerStrategy;
use Nesto\Auth\Strategies\JwtStrategy;
use Nesto\Auth\Strategies\LocalStrategy;
use Nesto\Auth\StrategyRegistry;
use Nesto\Common\Exceptions\UnauthorizedException;
use Nesto\Core\ExecutionContext;
use Nesto\Core\Routing\Route;
use Nesto\Http\Request;
use Nesto\Http\Response;
use Nesto\Jwt\JwtService;
use PHPUnit\Framework\TestCase;

final class StrategiesTest extends TestCase
{
    public function testExtractors(): void
    {
        $bearer = ExtractJwt::fromAuthHeaderAsBearerToken();
        self::assertSame('abc', $bearer(Request::create('GET', '/', headers: ['Authorization' => 'bearer abc'])));
        self::assertNull($bearer(Request::create('GET', '/', headers: ['Authorization' => 'Basic abc'])));
        self::assertNull($bearer(Request::create('GET', '/')));
        self::assertSame('h', ExtractJwt::fromHeader('x-token')(Request::create('GET', '/', headers: ['X-Token' => 'h'])));
        self::assertSame('q', ExtractJwt::fromQuery('token')(Request::create('GET', '/?token=q')));
        self::assertSame('b', ExtractJwt::fromBodyField('token')(Request::create('POST', '/', body: ['token' => 'b'])));
        $cookie = new Request(Request::create('GET', '/')->psr()->withCookieParams(['jwt' => 'c']));
        self::assertSame('c', ExtractJwt::fromCookie('jwt')($cookie));
        $combined = ExtractJwt::fromExtractors([ExtractJwt::fromHeader('x-token'), ExtractJwt::fromQuery('token')]);
        self::assertSame('q', $combined(Request::create('GET', '/?token=q')));
    }

    public function testJwtStrategyDefaultsAndOptions(): void
    {
        $jwt = new JwtService(['secret' => 's']);
        $strategy = new JwtStrategy($jwt, ['jwtFromRequest' => ExtractJwt::fromQuery('t'), 'verifyOptions' => ['issuer' => 'me']]);
        self::assertSame('jwt', $strategy->name());
        $token = $jwt->sign(['sub' => 7], ['issuer' => 'me']);
        self::assertSame(7, $strategy->authenticate(Request::create('GET', "/?t=$token"))['sub']);

        $wrongIssuer = $jwt->sign(['sub' => 7], ['issuer' => 'other']);
        $this->expectException(UnauthorizedException::class);
        $strategy->authenticate(Request::create('GET', "/?t=$wrongIssuer"));
    }

    public function testLocalApiKeyAndBearerStrategies(): void
    {
        $local = new class(['usernameField' => 'email']) extends LocalStrategy {
            public function validate(string $username, string $password): mixed
            {
                return $username === 'a@b.c' && $password === 'pw' ? ['id' => 1] : null;
            }
        };
        self::assertSame('local', $local->name());
        self::assertSame(['id' => 1], $local->authenticate(Request::create('POST', '/', body: ['email' => 'a@b.c', 'password' => 'pw'])));
        try {
            $local->authenticate(Request::create('POST', '/', body: ['email' => 'a@b.c', 'password' => 'no']));
            self::fail();
        } catch (UnauthorizedException $e) {
            self::assertSame('Invalid credentials', $e->getMessage());
        }
        try {
            $local->authenticate(Request::create('POST', '/', body: []));
            self::fail();
        } catch (UnauthorizedException $e) {
            self::assertSame('Missing credentials', $e->getMessage());
        }

        $apiKey = new class(['query' => 'api_key']) extends ApiKeyStrategy {
            public function validate(string $key): mixed
            {
                return $key === 'k1' ? 'service' : null;
            }
        };
        self::assertSame('api-key', $apiKey->name());
        self::assertSame('service', $apiKey->authenticate(Request::create('GET', '/', headers: ['X-Api-Key' => 'k1'])));
        self::assertSame('service', $apiKey->authenticate(Request::create('GET', '/?api_key=k1')));
        $this->expectException(UnauthorizedException::class);
        $apiKey->authenticate(Request::create('GET', '/', headers: ['X-Api-Key' => 'nope']));
    }

    public function testBearerStrategyAndGuardWithManualRegistry(): void
    {
        $bearer = new class extends BearerStrategy {
            public function validate(string $token): mixed
            {
                return $token === 'opaque' ? (object) ['id' => 3, 'role' => 'editor'] : null;
            }
        };
        $registry = (new StrategyRegistry)->register($bearer);
        $guard = new AuthGuard('bearer', $registry);

        $request = Request::create('GET', '/x', headers: ['Authorization' => 'Bearer opaque']);
        $ctx = new ExecutionContext($request, new Response, new Route('GET', '/x', GuardTarget::class, 'editors'));
        self::assertTrue($guard->canActivate($ctx));
        self::assertSame(3, $request->user()->id);

        // RolesGuard reads $user->role and #[Roles] from the handler
        self::assertTrue((new RolesGuard)->canActivate($ctx));
        $ctxAdmin = new ExecutionContext($request, new Response, new Route('GET', '/y', GuardTarget::class, 'admins'));
        try {
            (new RolesGuard)->canActivate($ctxAdmin);
            self::fail();
        } catch (\Nesto\Common\Exceptions\ForbiddenException) {
            self::assertTrue(true);
        }

        // unknown strategy is a programming error, not a 401
        $this->expectException(\LogicException::class);
        (new AuthGuard('nope', $registry))->canActivate($ctx);
    }

    public function testHash(): void
    {
        $hash = Hash::make('pw');
        self::assertTrue(Hash::check('pw', $hash));
        self::assertFalse(Hash::check('other', $hash));
        self::assertFalse(Hash::check('pw', null));
        self::assertFalse(Hash::needsRehash($hash));
        self::assertSame('bcrypt', Hash::info($hash)['algoName']);
        Hash::useBcrypt(cost: 5);
        self::assertTrue(Hash::needsRehash($hash));
        Hash::useBcrypt();
    }
}

final class GuardTarget
{
    #[Roles('editor', 'admin')]
    public function editors(): void {}

    #[Roles('admin')]
    public function admins(): void {}
}
