<?php

declare(strict_types=1);

namespace Nesto\Core\Http;

use Nesto\Common\Contracts\Middleware;
use Nesto\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as Psr15Middleware;
use Psr\Http\Server\RequestHandlerInterface;

/** Runs Nesto middleware, PSR-15 middleware and closures as one chain ending in $core. */
final class MiddlewarePipeline
{
    /**
     * @param list<Middleware|Psr15Middleware|\Closure> $middlewares
     * @param \Closure(Request): ResponseInterface      $core
     */
    public static function run(array $middlewares, Request $request, \Closure $core): ResponseInterface
    {
        $next = $core;
        foreach (array_reverse($middlewares) as $mw) {
            $next = self::wrap($mw, $next);
        }

        return $next($request);
    }

    private static function wrap(Middleware|Psr15Middleware|\Closure $mw, \Closure $next): \Closure
    {
        if ($mw instanceof Middleware) {
            return fn (Request $r) => $mw->use($r, $next);
        }
        if ($mw instanceof Psr15Middleware) {
            return function (Request $r) use ($mw, $next) {
                $handler = new class($next, $r) implements RequestHandlerInterface {
                    public function __construct(private readonly \Closure $next, private readonly Request $request) {}

                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        return ($this->next)($this->request->withPsr($request));
                    }
                };

                return $mw->process($r->psr(), $handler);
            };
        }

        // Closure(Request $request, Closure $next): ResponseInterface
        return fn (Request $r) => $mw($r, $next);
    }
}
