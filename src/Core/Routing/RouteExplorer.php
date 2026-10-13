<?php

declare(strict_types=1);

namespace Nesto\Core\Routing;

use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Header;
use Nesto\Common\Attributes\HttpCode;
use Nesto\Common\Attributes\Redirect;
use Nesto\Common\Attributes\RouteAttribute;
use Nesto\Common\Attributes\Version;
use Nesto\Core\ContainerException;
use Nesto\Core\ModuleRef;
use ReflectionClass;
use ReflectionMethod;

/** Reads #[Controller] + #[Get]/#[Post]/… attributes and produces Route objects. */
final class RouteExplorer
{
    /** @var list<array{regex: string, method: ?string}> */
    private readonly array $prefixExclude;

    /**
     * @param list<string|array{path: string, method?: string}> $prefixExclude routes served without the global prefix
     *        (Nest's `setGlobalPrefix('api', { exclude: [...] })`), matched against the controller path + route path
     */
    public function __construct(
        private readonly string $globalPrefix = '',
        private readonly ?string $versionPrefix = null, // e.g. 'v' → /v1/cats when #[Version('1')]
        private readonly ?string $defaultVersion = null,
        array $prefixExclude = [],
    ) {
        $this->prefixExclude = array_map(static function (string|array $route): array {
            $path = is_array($route) ? (string) ($route['path'] ?? '') : $route;
            $method = is_array($route) && isset($route['method']) ? strtoupper((string) $route['method']) : null;

            return ['regex' => Route::compile(Router::join($path))[0], 'method' => $method];
        }, $prefixExclude);
    }

    /** @return list<Route> */
    public function explore(ModuleRef $module): array
    {
        $routes = [];
        foreach ($module->controllers as $controller) {
            array_push($routes, ...$this->exploreController($controller, $module->class));
        }

        return $routes;
    }

    /** @return list<Route> */
    public function exploreController(string $controller, ?string $moduleClass = null): array
    {
        $class = new ReflectionClass($controller);
        $ctrlAttr = ($class->getAttributes(Controller::class)[0] ?? null)?->newInstance();
        if ($ctrlAttr === null) {
            throw new ContainerException("$controller is listed in controllers but has no #[Controller] attribute.");
        }
        $prefixes = (array) $ctrlAttr->path;
        $classVersions = ($class->getAttributes(Version::class)[0] ?? null)?->newInstance()?->versions;

        $routes = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(RouteAttribute::class, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
                /** @var RouteAttribute $ra */
                $ra = $attr->newInstance();
                $meta = $this->meta($method);
                $versions = ($method->getAttributes(Version::class)[0] ?? null)?->newInstance()?->versions ?? $classVersions;
                $versionSegments = $this->versionSegments($versions);

                foreach ($prefixes as $prefix) {
                    foreach ($ra->paths as $subPath) {
                        $excluded = $this->isPrefixExcluded($ra->method(), Router::join($prefix, $subPath));
                        foreach ($versionSegments as $v) {
                            $path = Router::join($excluded ? '' : $this->globalPrefix, $v, $prefix, $subPath);
                            $extra = ['version' => $v ?: null] + ($excluded ? ['prefixExcluded' => true] : []) + ($ctrlAttr->host !== null ? ['host' => (array) $ctrlAttr->host] : []);
                            $routes[] = new Route($ra->method(), $path, $controller, $method->getName(), $meta + $extra, $moduleClass);
                        }
                    }
                }
            }
        }

        return $routes;
    }

    /** @return list<string> */
    private function versionSegments(?array $versions): array
    {
        if ($this->versionPrefix === null) {
            return [''];
        }
        $versions ??= $this->defaultVersion !== null ? [$this->defaultVersion] : [];
        if ($versions === []) {
            return [''];
        }

        return array_values(array_unique(array_map(
            fn (string $v) => $v === Version::NEUTRAL ? '' : $this->versionPrefix . $v,
            $versions,
        )));
    }

    private function isPrefixExcluded(string $httpMethod, string $path): bool
    {
        if ($this->globalPrefix === '') {
            return false;
        }
        foreach ($this->prefixExclude as $rule) {
            if ($rule['method'] !== null && $rule['method'] !== $httpMethod && $httpMethod !== '*') {
                continue;
            }
            if (preg_match($rule['regex'], $path)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function meta(ReflectionMethod $method): array
    {
        $meta = [];
        if ($hc = $method->getAttributes(HttpCode::class)[0] ?? null) {
            $meta['httpCode'] = $hc->newInstance()->code;
        }
        $headers = [];
        foreach ($method->getAttributes(Header::class) as $h) {
            $h = $h->newInstance();
            $headers[$h->name] = $h->value;
        }
        if ($headers !== []) {
            $meta['headers'] = $headers;
        }
        if ($r = $method->getAttributes(Redirect::class)[0] ?? null) {
            $r = $r->newInstance();
            $meta['redirect'] = ['url' => $r->url, 'status' => $r->statusCode];
        }

        return $meta;
    }
}
