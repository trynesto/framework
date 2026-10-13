<?php

declare(strict_types=1);

namespace Nesto\Throttler;

use Nesto\Common\Attributes\Catches;
use Nesto\Common\Contracts\ExceptionFilter;
use Nesto\Core\ArgumentsHost;
use Nesto\Http\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Turns a ThrottlerException into the standard 429 body while keeping the Retry-After /
 * X-RateLimit-* headers (the core fallback filter drops builder headers). ThrottlerModule
 * registers it as a global APP_FILTER automatically.
 */
#[Catches(ThrottlerException::class)]
final class ThrottlerExceptionFilter implements ExceptionFilter
{
    public function catch(\Throwable $exception, ArgumentsHost $host): ResponseInterface
    {
        /** @var ThrottlerException $exception */
        $response = Response::json($exception->getResponse(), $exception->getStatus());
        foreach ($exception->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $host->switchToHttp()->getResponse()->decorate($response);
    }
}
