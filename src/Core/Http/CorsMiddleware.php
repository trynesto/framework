<?php

declare(strict_types=1);

namespace Nesto\Core\Http;

use Nesto\Common\Contracts\Middleware;
use Nesto\Http\Request;
use Nesto\Http\Response;
use Psr\Http\Message\ResponseInterface;

/** $app->enableCors([...]) — options mirror Nest/express cors: origin, methods, allowedHeaders, exposedHeaders, credentials, maxAge. */
final class CorsMiddleware implements Middleware
{
    private array $options;

    public function __construct(array $options = [])
    {
        $this->options = $options + [
            'origin' => '*',
            'methods' => 'GET,HEAD,PUT,PATCH,POST,DELETE,OPTIONS',
            'allowedHeaders' => null,
            'exposedHeaders' => null,
            'credentials' => false,
            'maxAge' => null,
        ];
    }

    public function use(Request $request, \Closure $next): ResponseInterface
    {
        $origin = $request->header('Origin');
        $allowOrigin = $this->resolveOrigin($origin);

        if ($request->isMethod('OPTIONS') && $request->header('Access-Control-Request-Method') !== null) {
            $response = Response::noContent();
            $response = $this->apply($response, $allowOrigin);
            $response = $response->withHeader('Access-Control-Allow-Methods', is_array($this->options['methods']) ? implode(',', $this->options['methods']) : $this->options['methods']);
            $reqHeaders = $this->options['allowedHeaders'] ?? $request->header('Access-Control-Request-Headers');
            if ($reqHeaders) {
                $response = $response->withHeader('Access-Control-Allow-Headers', is_array($reqHeaders) ? implode(',', $reqHeaders) : $reqHeaders);
            }
            if ($this->options['maxAge'] !== null) {
                $response = $response->withHeader('Access-Control-Max-Age', (string) $this->options['maxAge']);
            }

            return $response;
        }

        return $this->apply($next($request), $allowOrigin);
    }

    private function resolveOrigin(?string $origin): ?string
    {
        $allowed = $this->options['origin'];
        if ($allowed === '*' || $allowed === true) {
            return $this->options['credentials'] && $origin ? $origin : '*';
        }
        if ($allowed === false) {
            return null;
        }
        if ($allowed instanceof \Closure) {
            return $allowed($origin) ? $origin : null;
        }
        foreach ((array) $allowed as $a) {
            if ($a === $origin || (str_starts_with($a, '/') && $origin && preg_match($a, $origin))) {
                return $origin;
            }
        }

        return null;
    }

    private function apply(ResponseInterface $response, ?string $allowOrigin): ResponseInterface
    {
        if ($allowOrigin === null) {
            return $response;
        }
        $response = $response->withHeader('Access-Control-Allow-Origin', $allowOrigin);
        if ($allowOrigin !== '*') {
            $response = $response->withAddedHeader('Vary', 'Origin');
        }
        if ($this->options['credentials']) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }
        if ($this->options['exposedHeaders']) {
            $e = $this->options['exposedHeaders'];
            $response = $response->withHeader('Access-Control-Expose-Headers', is_array($e) ? implode(',', $e) : $e);
        }

        return $response;
    }
}
