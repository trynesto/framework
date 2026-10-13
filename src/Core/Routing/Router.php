<?php

declare(strict_types=1);

namespace Nesto\Core\Routing;

use Nesto\Common\Exceptions\MethodNotAllowedException;
use Nesto\Common\Exceptions\NotFoundException;

/**
 * Fast matcher: static routes go to a hash map, dynamic routes to a per-method list of compiled regexes.
 * Paths are normalised to "/segment/segment" (no trailing slash; "/" for root).
 */
final class Router
{
    /** @var array<string, array<string, list<Route>>> method => path => routes (several when they differ by host) */
    private array $static = [];
    /** @var array<string, list<Route>> method => routes */
    private array $dynamic = [];
    /** @var list<Route> */
    private array $all = [];

    public function add(Route $route): void
    {
        $this->all[] = $route;
        $methods = $route->method === '*' ? ['*'] : [$route->method];
        foreach ($methods as $m) {
            if ($route->isStatic) {
                $this->static[$m][$route->path][] = $route;
            } else {
                $this->dynamic[$m][] = $route;
            }
        }
    }

    /** @param string|null $host request host, checked against #[Controller(host: …)] */
    public function match(string $method, string $path, ?string $host = null): RouteMatch
    {
        $path = self::normalise($path);
        $method = strtoupper($method);
        $candidates = $method === 'HEAD' ? ['HEAD', 'GET', '*'] : [$method, '*'];

        foreach ($candidates as $m) {
            foreach ($this->static[$m][$path] ?? [] as $route) {
                if (($hostParams = $route->matchHost($host)) !== null) {
                    return new RouteMatch($route, [], $hostParams);
                }
            }
        }
        foreach ($candidates as $m) {
            foreach ($this->dynamic[$m] ?? [] as $route) {
                if (preg_match($route->regex, $path, $matches) && ($hostParams = $route->matchHost($host)) !== null) {
                    $params = [];
                    foreach ($route->paramNames as $name) {
                        $params[$name] = rawurldecode($matches[$name]);
                    }

                    return new RouteMatch($route, $params, $hostParams);
                }
            }
        }

        // 405 if the path exists under another method
        $allowed = [];
        foreach ($this->all as $route) {
            if (($route->path === $path || preg_match($route->regex, $path)) && $route->matchHost($host) !== null) {
                $allowed[] = $route->method;
            }
        }
        if ($allowed !== []) {
            throw new MethodNotAllowedException("Method $method not allowed for $path. Allowed: " . implode(', ', array_unique($allowed)));
        }

        throw new NotFoundException("Cannot $method $path");
    }

    public static function normalise(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '//' ? '/' : $path;
    }

    public static function join(string ...$segments): string
    {
        $parts = [];
        foreach ($segments as $s) {
            $s = trim($s, '/');
            if ($s !== '') {
                $parts[] = $s;
            }
        }

        return '/' . implode('/', $parts);
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->all;
    }
}
