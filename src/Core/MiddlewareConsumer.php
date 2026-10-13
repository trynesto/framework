<?php

declare(strict_types=1);

namespace Nesto\Core;

/**
 * Nest's MiddlewareConsumer:
 *   $consumer->apply(LoggerMiddleware::class, Cors::class)
 *            ->exclude('cats/{id}', ['path' => 'health', 'method' => 'GET'])
 *            ->forRoutes(CatsController::class, 'users/*', ['path' => 'orders', 'method' => 'POST']);
 */
final class MiddlewareConsumer
{
    /** @var list<array{middlewares: list<class-string|object>, routes: list<array{path:?string,method:?string,controller:?string}>, exclude: list<array{path:?string,method:?string,controller:?string}>}> */
    private array $bindings = [];
    private array $pending = [];

    public function apply(string|object ...$middlewares): self
    {
        $this->pending = ['middlewares' => array_values($middlewares), 'routes' => [], 'exclude' => []];

        return $this;
    }

    public function exclude(string|array ...$routes): self
    {
        $this->pending['exclude'] = array_map([$this, 'normalise'], $routes);

        return $this;
    }

    public function forRoutes(string|array ...$routes): self
    {
        $this->pending['routes'] = $routes === [] ? [['path' => '*', 'method' => null, 'controller' => null]] : array_map([$this, 'normalise'], $routes);
        $this->bindings[] = $this->pending;
        $this->pending = [];

        return $this;
    }

    private function normalise(string|array $route): array
    {
        if (is_array($route)) {
            return ['path' => $route['path'] ?? '*', 'method' => isset($route['method']) ? strtoupper($route['method']) : null, 'controller' => null];
        }
        if (class_exists($route)) {
            return ['path' => null, 'method' => null, 'controller' => $route];
        }

        return ['path' => $route, 'method' => null, 'controller' => null];
    }

    /** @return list<array> */
    public function bindings(): array
    {
        return $this->bindings;
    }
}
