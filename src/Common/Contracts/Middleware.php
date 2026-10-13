<?php

declare(strict_types=1);

namespace Nesto\Common\Contracts;

use Nesto\Http\Request;
use Psr\Http\Message\ResponseInterface;

/** Nest's NestMiddleware. Call $next($request) to continue. PSR-15 middleware are accepted everywhere too. */
interface Middleware
{
    /** @param \Closure(Request): ResponseInterface $next */
    public function use(Request $request, \Closure $next): ResponseInterface;
}
