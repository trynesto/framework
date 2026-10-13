<?php

declare(strict_types=1);

namespace Nesto\Tests\Core;

use Nesto\Common\Exceptions\MethodNotAllowedException;
use Nesto\Common\Exceptions\NotFoundException;
use Nesto\Core\Routing\Route;
use Nesto\Core\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $r = new Router;
        $r->add(new Route('GET', '/cats', 'C', 'index'));
        $r->add(new Route('GET', '/cats/{id}', 'C', 'show'));
        $r->add(new Route('GET', '/cats/{id:\d+}/photos/{photo}', 'C', 'photo'));
        $r->add(new Route('GET', '/users/:id', 'C', 'nest'));
        $r->add(new Route('GET', '/files/*', 'C', 'files'));
        $r->add(new Route('*', '/any', 'C', 'any'));

        return $r;
    }

    public function test_static_and_dynamic_matching(): void
    {
        $r = $this->router();
        $this->assertSame('index', $r->match('GET', '/cats/')->route->handler);
        $this->assertSame(['id' => '3'], $r->match('GET', '/cats/3')->params);
        $this->assertSame(['id' => '3', 'photo' => 'a b'], $r->match('GET', '/cats/3/photos/a%20b')->params);
        $this->assertSame(['id' => '9'], $r->match('GET', '/users/9')->params);
        $this->assertSame('files', $r->match('GET', '/files/a/b/c')->route->handler);
        $this->assertSame('any', $r->match('PATCH', '/any')->route->handler);
        $this->assertSame('index', $r->match('HEAD', '/cats')->route->handler);
    }

    public function test_regex_constraint(): void
    {
        $this->expectException(NotFoundException::class);
        $this->router()->match('GET', '/cats/abc/photos/1');
    }

    public function test_405(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        $this->router()->match('POST', '/cats/3');
    }
}
