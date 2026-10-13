<?php

declare(strict_types=1);

namespace Nesto\Tests\Throttler;

use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Get;
use Nesto\Common\Attributes\Module;
use Nesto\Common\Attributes\UseGuards;
use Nesto\Common\Provider;
use Nesto\Core\Constants;
use Nesto\Core\NestoFactory;
use Nesto\Http\Request;
use Nesto\Throttler\Attributes\SkipThrottle;
use Nesto\Throttler\Attributes\Throttle;
use Nesto\Throttler\ThrottlerGuard;
use Nesto\Throttler\ThrottlerModule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[Controller('t')]
#[UseGuards(ThrottlerGuard::class)]
final class ThrottledController
{
    #[Get('limited')]
    #[Throttle(limit: 3, ttl: 60)]
    public function limited(): array
    {
        return ['ok' => true];
    }

    #[Get('skip')]
    #[SkipThrottle]
    public function skip(): array
    {
        return ['ok' => true];
    }

    #[Get('default')]
    public function default(): array
    {
        return ['ok' => true];
    }
}

#[Controller('open')]
final class OpenController
{
    #[Get()]
    public function index(): array
    {
        return ['ok' => true];
    }
}

#[Module(imports: [new ThrottlerModule(ttl: 60, limit: 5)], controllers: [ThrottledController::class, OpenController::class])]
final class ThrottlerAppModule
{
}

#[Module(
    imports: [new ThrottlerModule(ttl: 60, limit: 2, ignoreUserAgents: ['/HealthBot/'])],
    controllers: [OpenController::class],
    providers: [new Provider(Constants::APP_GUARD, useClass: ThrottlerGuard::class)],
)]
final class GlobalThrottlerAppModule
{
}

final class ThrottlerAppTest extends TestCase
{
    private function get(\Nesto\Core\NestoApplication $app, string $path, string $ip = '10.0.0.1', array $headers = []): ResponseInterface
    {
        return $app->handle(Request::create('GET', $path, headers: ['X-Forwarded-For' => $ip] + $headers));
    }

    public function testFourthRequestIs429WithHeaders(): void
    {
        $app = NestoFactory::create(ThrottlerAppModule::class);

        foreach ([2, 1, 0] as $i => $remaining) {
            $res = $this->get($app, '/t/limited');
            self::assertSame(200, $res->getStatusCode(), 'request ' . ($i + 1));
            self::assertSame('3', $res->getHeaderLine('X-RateLimit-Limit'));
            self::assertSame((string) $remaining, $res->getHeaderLine('X-RateLimit-Remaining'));
            self::assertNotSame('', $res->getHeaderLine('X-RateLimit-Reset'));
        }

        $blocked = $this->get($app, '/t/limited');
        self::assertSame(429, $blocked->getStatusCode());
        self::assertNotSame('', $blocked->getHeaderLine('Retry-After'));
        self::assertSame('0', $blocked->getHeaderLine('X-RateLimit-Remaining'));
        $body = json_decode((string) $blocked->getBody(), true);
        self::assertSame(429, $body['statusCode']);
        self::assertSame('ThrottlerException: Too Many Requests', $body['message']);

        // another client is unaffected, and so is another route (per-route keys)
        self::assertSame(200, $this->get($app, '/t/limited', ip: '10.0.0.2')->getStatusCode());
        self::assertSame(200, $this->get($app, '/t/default')->getStatusCode());
        self::assertSame('5', $this->get($app, '/t/default')->getHeaderLine('X-RateLimit-Limit'));

        // #[SkipThrottle] never counts
        for ($i = 0; $i < 10; $i++) {
            $res = $this->get($app, '/t/skip');
            self::assertSame(200, $res->getStatusCode());
            self::assertFalse($res->hasHeader('X-RateLimit-Limit'));
        }

        // a controller without the guard is not throttled
        for ($i = 0; $i < 10; $i++) {
            self::assertSame(200, $this->get($app, '/open')->getStatusCode());
        }
    }

    public function testGlobalGuardViaAppGuardAndIgnoredUserAgents(): void
    {
        $app = NestoFactory::create(GlobalThrottlerAppModule::class);

        self::assertSame(200, $this->get($app, '/open')->getStatusCode());
        self::assertSame(200, $this->get($app, '/open')->getStatusCode());
        self::assertSame(429, $this->get($app, '/open')->getStatusCode());
        self::assertSame(200, $this->get($app, '/open', headers: ['User-Agent' => 'HealthBot/1.0'])->getStatusCode());
    }
}
