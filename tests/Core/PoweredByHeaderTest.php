<?php

declare(strict_types=1);

namespace Nesto\Tests\Core\PoweredBy;

use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Get;
use Nesto\Common\Attributes\Module;
use Nesto\Core\NestoFactory;
use Nesto\Http\Request;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

#[Controller('ping')]
final class PingController
{
    #[Get]
    public function ping(): array
    {
        return ['pong' => true];
    }
}

#[Module(controllers: [PingController::class])]
final class PingModule
{
}

final class PoweredByHeaderTest extends TestCase
{
    private static function get(\Nesto\Core\NestoApplication $app, string $path): \Psr\Http\Message\ResponseInterface
    {
        return $app->handle(new Request(new ServerRequest('GET', $path)));
    }

    public function testEveryResponseSaysNestoByDefault(): void
    {
        $app = NestoFactory::create(PingModule::class);

        self::assertSame('Nesto', self::get($app, '/ping')->getHeaderLine('X-Powered-By'));
        self::assertSame('Nesto', self::get($app, '/missing')->getHeaderLine('X-Powered-By')); // errors too
    }

    public function testItCanBeChangedOrRemoved(): void
    {
        self::assertSame('Acme', self::get(NestoFactory::create(PingModule::class)->setPoweredBy('Acme'), '/ping')->getHeaderLine('X-Powered-By'));
        self::assertFalse(self::get(NestoFactory::create(PingModule::class, ['poweredBy' => false]), '/ping')->hasHeader('X-Powered-By'));
        self::assertFalse(self::get(NestoFactory::create(PingModule::class)->setPoweredBy(false), '/ping')->hasHeader('X-Powered-By'));
    }
}
