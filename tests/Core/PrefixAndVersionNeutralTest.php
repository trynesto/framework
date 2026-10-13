<?php

declare(strict_types=1);

namespace Nesto\Tests\Core\PrefixFixtures;

use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Get;
use Nesto\Common\Attributes\Module;
use Nesto\Common\Attributes\Version;
use Nesto\Common\Contracts\Middleware;
use Nesto\Common\Contracts\NestoModule;
use Nesto\Core\MiddlewareConsumer;
use Nesto\Http\Request;
use Psr\Http\Message\ResponseInterface;

#[Controller('health')]
#[Version(VERSION_NEUTRAL)]
final class HealthController
{
    #[Get]
    public function check(): array
    {
        return ['status' => 'ok'];
    }
}

#[Controller('items')]
final class ItemsController
{
    #[Get]
    public function list(): array
    {
        return ['v' => 1];
    }

    #[Get]
    #[Version('2', Version::NEUTRAL)]
    public function listV2(): array
    {
        return ['v' => 2];
    }
}

final class TagMiddleware implements Middleware
{
    public function use(Request $request, \Closure $next): ResponseInterface
    {
        return $next($request)->withHeader('X-Tagged', 'yes');
    }
}

#[Module(controllers: [HealthController::class, ItemsController::class])]
final class PrefixAppModule implements NestoModule
{
    public function configure(MiddlewareConsumer $consumer): void
    {
        $consumer->apply(TagMiddleware::class)->forRoutes('health');
    }
}

namespace Nesto\Tests\Core;

use Nesto\Common\Attributes\Version;
use Nesto\Core\NestoFactory;
use Nesto\Http\Request;
use Nesto\Tests\Core\PrefixFixtures\PrefixAppModule;
use PHPUnit\Framework\TestCase;

final class PrefixAndVersionNeutralTest extends TestCase
{
    public function test_version_neutral_and_prefix_exclude(): void
    {
        $app = NestoFactory::create(PrefixAppModule::class)
            ->setGlobalPrefix('api', ['exclude' => ['health']])
            ->enableVersioning(['defaultVersion' => '1']);

        $ids = array_map(fn ($r) => $r->id(), $app->router()->routes());
        self::assertContains('GET /health', $ids);
        self::assertContains('GET /api/v1/items', $ids);
        self::assertContains('GET /api/v2/items', $ids);
        self::assertContains('GET /api/items', $ids);
        self::assertNotContains('GET /api/health', $ids);
        self::assertNotContains('GET /api/v1/health', $ids);

        $res = $app->handle(Request::create('GET', '/health'));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('yes', $res->getHeaderLine('X-Tagged'), 'forRoutes() paths follow the prefix exclusion');

        self::assertSame('{"v":2}', (string) $app->handle(Request::create('GET', '/api/v2/items'))->getBody());
        self::assertSame('{"v":2}', (string) $app->handle(Request::create('GET', '/api/items'))->getBody());
        self::assertSame('{"v":1}', (string) $app->handle(Request::create('GET', '/api/v1/items'))->getBody());
    }

    public function test_prefix_exclude_with_method_rule(): void
    {
        $app = NestoFactory::create(PrefixAppModule::class)
            ->setGlobalPrefix('api', ['exclude' => [['path' => 'health', 'method' => 'POST']]]);

        $ids = array_map(fn ($r) => $r->id(), $app->router()->routes());
        self::assertContains('GET /api/health', $ids);
        self::assertSame(Version::NEUTRAL, VERSION_NEUTRAL);
    }
}
