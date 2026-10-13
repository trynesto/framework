<?php

declare(strict_types=1);

namespace Nesto\Core;

use Nesto\Common\Contracts\BeforeApplicationShutdown;
use Nesto\Common\Contracts\ExceptionFilter;
use Nesto\Common\Contracts\Guard;
use Nesto\Common\Contracts\Interceptor;
use Nesto\Common\Contracts\Middleware;
use Nesto\Common\Contracts\NestoModule;
use Nesto\Common\Contracts\OnApplicationBootstrap;
use Nesto\Common\Contracts\OnApplicationShutdown;
use Nesto\Common\Contracts\OnModuleDestroy;
use Nesto\Common\Contracts\OnModuleInit;
use Nesto\Common\Contracts\Pipe;
use Nesto\Common\Scope;
use Nesto\Core\Http\CorsMiddleware;
use Nesto\Core\Http\MiddlewarePipeline;
use Nesto\Core\Http\RouteHandler;
use Nesto\Core\Platform\BootErrorHandler;
use Nesto\Core\Platform\FrankenPhpAdapter;
use Nesto\Core\Routing\Route;
use Nesto\Core\Routing\RouteExplorer;
use Nesto\Core\Routing\Router;
use Nesto\Http\Request;
use Nesto\Http\Response;
use Nesto\Http\ResponseEmitter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface as Psr15Middleware;

/**
 * The object returned by NestoFactory::create(). Configure it, then listen() (FPM) or handle($request).
 */
final class NestoApplication
{
    private Router $router;
    private RouteHandler $handler;
    private string $globalPrefix = '';
    /** @var list<string|array{path: string, method?: string}> */
    private array $globalPrefixExclude = [];
    private ?array $versioning = null;
    /** @var list<Middleware|Psr15Middleware|\Closure> */
    private array $globalMiddleware = [];
    /** @var list<array{middlewares: list<object>, routes: list<array>, exclude: list<array>}> */
    private array $middlewareBindings = [];
    /** @var array<string, ModuleRef> route id => module */
    private array $routeModules = [];
    private bool $initialized = false;
    private static ?\Closure $listenHook = null;
    /** @var list<string>|null */
    private ?array $versionSegments = null;
    private bool $shutdownHooksEnabled = false;
    /** X-Powered-By value (like Express's default `X-Powered-By: Express`); false sends none. */
    private string|false $poweredBy;
    /** @var list<\Closure> */
    private array $pendingGlobals = [];

    public function __construct(
        private readonly Container $container,
        private readonly ModuleRef $root,
        private readonly Reflector $reflector,
        private readonly array $options = [],
    ) {
        $this->router = new Router;
        $this->poweredBy = $options['poweredBy'] ?? 'Nesto';
        // without an explicit 'debug' option the APP_DEBUG env var decides, read lazily (after ConfigModule loaded .env)
        $this->handler = new RouteHandler($container, $reflector, isset($options['debug']) ? (bool) $options['debug'] : null);
        $container->core(Reflector::class, $reflector);
        $container->core(self::class, $this);
        $container->core(Container::class, $container);
        $container->core(DiscoveryService::class, new DiscoveryService($container));
        $container->requestToken(Constants::REQUEST);
        $container->requestToken(Request::class);
    }

    // ---- configuration (before init) ------------------------------------------

    /**
     * Nest's app.setGlobalPrefix('api', { exclude: ['health', { path: 'feed', method: 'GET' }] }).
     *
     * @param array{exclude?: list<string|array{path: string, method?: string}>} $options
     *        exclude: route paths (controller path + route path, wildcards allowed) served without the prefix
     */
    public function setGlobalPrefix(string $prefix, array $options = []): self
    {
        $this->globalPrefix = trim($prefix, '/');
        $this->globalPrefixExclude = array_values($options['exclude'] ?? []);

        return $this;
    }

    /** @param array{type?: 'uri', prefix?: string, defaultVersion?: string} $options */
    public function enableVersioning(array $options = []): self
    {
        $this->versioning = $options + ['type' => 'uri', 'prefix' => 'v', 'defaultVersion' => null];

        return $this;
    }

    /**
     * The `X-Powered-By` response header, `Nesto` by default (it also replaces PHP's own `PHP/8.x` value).
     * `false` removes it, like Express's `app.disable('x-powered-by')`; same as the `poweredBy` factory option.
     */
    public function setPoweredBy(string|false $value): self
    {
        $this->poweredBy = $value;

        return $this;
    }

    public function enableCors(array $options = []): self
    {
        array_unshift($this->globalMiddleware, new CorsMiddleware($options));

        return $this;
    }

    /** Global middleware (app.use() in Nest/express). Classes are resolved through the container. */
    public function use(string|object ...$middlewares): self
    {
        foreach ($middlewares as $mw) {
            $this->globalMiddleware[] = is_string($mw) ? $this->container->resolve($mw, null, strict: false) : $mw;
        }

        return $this;
    }

    public function useGlobalGuards(string|Guard ...$guards): self
    {
        $this->pendingGlobals[] = $apply = fn () => $this->handler->addGlobalGuards(...array_map(fn ($g) => $this->handler->instance($g, $this->root, Guard::class), $guards));
        $this->applyIfInitialized($apply);

        return $this;
    }

    public function useGlobalPipes(string|Pipe ...$pipes): self
    {
        $this->pendingGlobals[] = $apply = fn () => $this->handler->addGlobalPipes(...array_map(fn ($p) => $this->handler->instance($p, $this->root, Pipe::class), $pipes));
        $this->applyIfInitialized($apply);

        return $this;
    }

    public function useGlobalInterceptors(string|Interceptor ...$interceptors): self
    {
        $this->pendingGlobals[] = $apply = fn () => $this->handler->addGlobalInterceptors(...array_map(fn ($i) => $this->handler->instance($i, $this->root, Interceptor::class), $interceptors));
        $this->applyIfInitialized($apply);

        return $this;
    }

    public function useGlobalFilters(string|ExceptionFilter ...$filters): self
    {
        $this->pendingGlobals[] = $apply = fn () => $this->handler->addGlobalFilters(...array_map(fn ($f) => $this->handler->instance($f, $this->root, ExceptionFilter::class), $filters));
        $this->applyIfInitialized($apply);

        return $this;
    }

    private function applyIfInitialized(\Closure $apply): void
    {
        if ($this->initialized) {
            $apply();
            array_pop($this->pendingGlobals);
        }
    }

    public function enableShutdownHooks(): self
    {
        $this->shutdownHooksEnabled = true;
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $sig) {
                pcntl_signal($sig, function () use ($sig) {
                    $this->close($sig === SIGTERM ? 'SIGTERM' : 'SIGINT');
                    exit(0);
                });
            }
        }

        return $this;
    }

    // ---- container access -------------------------------------------------------

    /** Like app.get() in Nest: resolve any provider, ignoring module boundaries. */
    public function get(string $token): mixed
    {
        $this->init();

        return $this->container->resolve($token, null);
    }

    public function select(string $moduleClass): ModuleRef
    {
        return $this->container->module($moduleClass);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function router(): Router
    {
        $this->init();

        return $this->router;
    }

    public function reflector(): Reflector
    {
        return $this->reflector;
    }

    public function globalPrefix(): string
    {
        return $this->globalPrefix;
    }

    // ---- bootstrap -------------------------------------------------------------------

    public function init(): self
    {
        if ($this->initialized) {
            return $this;
        }
        $this->initialized = true;

        // APP_* providers → global enhancers (with DI)
        foreach ($this->container->modules() as $module) {
            foreach ($module->appProviders(Constants::APP_GUARD) as $def) {
                $this->handler->addGlobalGuards($this->resolveDef($def));
            }
            foreach ($module->appProviders(Constants::APP_PIPE) as $def) {
                $this->handler->addGlobalPipes($this->resolveDef($def));
            }
            foreach ($module->appProviders(Constants::APP_INTERCEPTOR) as $def) {
                $this->handler->addGlobalInterceptors($this->resolveDef($def));
            }
            foreach ($module->appProviders(Constants::APP_FILTER) as $def) {
                $this->handler->addGlobalFilters($this->resolveDef($def));
            }
        }
        foreach ($this->pendingGlobals as $apply) {
            $apply();
        }
        $this->pendingGlobals = [];

        // routes
        $explorer = new RouteExplorer(
            $this->globalPrefix,
            $this->versioning['prefix'] ?? null,
            $this->versioning['defaultVersion'] ?? null,
            $this->globalPrefixExclude,
        );
        foreach ($this->container->modules() as $module) {
            foreach ($explorer->explore($module) as $route) {
                $this->router->add($route);
                $this->routeModules[$route->id()] = $module;
            }
        }

        // eager instantiation (like Nest) + module middleware configuration
        foreach ($this->container->modules() as $module) {
            if ($module->class === '__core__') {
                continue;
            }
            foreach ($module->providers as $def) {
                if ($def->scope() === Scope::DEFAULT && ! $def->provider->hasValue) {
                    try {
                        $module->resolve(array_search($def, $module->providers, true));
                    } catch (OutsideRequestScopeException) {
                        // depends on the current request → instantiated per request instead (scope bubbling)
                    }
                }
            }
            if (class_exists($module->class) && ! in_array($module->class, $module->controllers, true)) {
                try {
                    $module->instance ??= $this->container->instantiate($module->class, $module);
                } catch (ContainerException) {
                    // e.g. a DynamicModuleFactory with a variadic constructor: only needed for configure()/hooks
                    $module->instance ??= (new \ReflectionClass($module->class))->newInstanceWithoutConstructor();
                }
                if ($module->instance instanceof NestoModule) {
                    $consumer = new MiddlewareConsumer;
                    $module->instance->configure($consumer);
                    foreach ($consumer->bindings() as $binding) {
                        $binding['middlewares'] = array_map(fn ($m) => $this->handler->instance($m, $module, null), $binding['middlewares']);
                        $this->middlewareBindings[] = $binding;
                    }
                }
            }
        }

        $this->callHook(OnModuleInit::class, 'onModuleInit');
        $this->callHook(OnApplicationBootstrap::class, 'onApplicationBootstrap');

        return $this;
    }

    private function resolveDef(ProviderDefinition $def): object
    {
        $key = array_search($def, $def->module->providers, true);

        return $this->container->resolve((string) $key, $def->module);
    }

    private function callHook(string $interface, string $method, mixed ...$args): void
    {
        $seen = [];
        $targets = [...$this->container->instances(), ...array_filter(array_map(fn (ModuleRef $m) => $m->instance, $this->container->modules()))];
        foreach ($targets as $instance) {
            $id = spl_object_id($instance);
            if (isset($seen[$id]) || ! $instance instanceof $interface) {
                continue;
            }
            $seen[$id] = true;
            $instance->{$method}(...$args);
        }
    }

    // ---- request handling ------------------------------------------------------------

    public function handle(Request $request): ResponseInterface
    {
        $response = $this->dispatch($request);
        if ($this->poweredBy === false) {
            return $response->withoutHeader('X-Powered-By');
        }

        return $response->hasHeader('X-Powered-By') ? $response : $response->withHeader('X-Powered-By', $this->poweredBy);
    }

    private function dispatch(Request $request): ResponseInterface
    {
        $this->init();
        $this->container->requestInstance(Constants::REQUEST, $request);
        $this->container->requestInstance(Request::class, $request);
        \Nesto\Common\Debug\Dumper::enterRequest(); // console() answers instead of exiting the worker

        try {
            $match = null;
            $matchError = null;
            try {
                $match = $this->router->match($request->method(), $request->path(), $request->psr()->getUri()->getHost() ?: $request->header('Host'));
            } catch (\Throwable $e) {
                $matchError = $e;
            }

            $module = $match ? ($this->routeModules[$match->route->id()] ?? $this->root) : null;
            if ($match) {
                $request->setAttribute(Constants::ROUTE_PARAMS, $match->params);
                $request->setAttribute(Constants::ROUTE, $match->route);
                if ($match->hostParams !== []) {
                    $request->setAttribute(Constants::HOST_PARAMS, $match->hostParams);
                }
            }

            $middlewares = [...$this->globalMiddleware, ...$this->middlewareFor($request, $match?->route)];

            $core = function (Request $req) use ($match, $matchError, $module) {
                if ($matchError !== null) {
                    // 404/405 become a response here, so middleware sees it after $next() like in Nest
                    return $this->handler->handleException($matchError, new ArgumentsHost($req, new Response));
                }

                return $this->handler->handle($match->route, $req, $module);
            };

            try {
                return MiddlewarePipeline::run($middlewares, $request, $core);
            } catch (\Throwable $e) {
                // middleware errors: global filters only, like Nest (route/controller filters wrap the handler)
                return $this->handler->handleException($e, new ArgumentsHost($request, new Response), $module);
            }
        } finally {
            \Nesto\Common\Debug\Dumper::leaveRequest();
            $this->container->endRequest();
        }
    }

    /** @return list<object> */
    private function middlewareFor(Request $request, ?Route $route): array
    {
        if ($this->middlewareBindings === []) {
            return [];
        }
        $path = $request->path();
        $method = $request->method();
        $out = [];
        foreach ($this->middlewareBindings as $binding) {
            $applies = false;
            foreach ($binding['routes'] as $r) {
                if ($this->routeInfoMatches($r, $path, $method, $route)) {
                    $applies = true;
                    break;
                }
            }
            if (! $applies) {
                continue;
            }
            foreach ($binding['exclude'] as $r) {
                if ($this->routeInfoMatches($r, $path, $method, $route)) {
                    $applies = false;
                    break;
                }
            }
            if ($applies) {
                array_push($out, ...$binding['middlewares']);
            }
        }

        return $out;
    }

    /**
     * Nest semantics: a path in forRoutes()/exclude() is the route path as declared ('cats', 'cats/*'), matched
     * whatever global prefix and URI version the request carries. The full path ('api/v1/cats') matches too.
     */
    private function routeInfoMatches(array $info, string $path, string $method, ?Route $route): bool
    {
        if ($info['controller'] !== null) {
            return $route !== null && $route->controller === $info['controller'];
        }
        if ($info['method'] !== null && $info['method'] !== 'ALL' && $info['method'] !== $method) {
            return false;
        }
        if (($info['path'] ?? '*') === '*' || Router::join($info['path']) === '/*') {
            return true;
        }
        [$regex] = Route::compile(Router::join($info['path']));
        foreach ($this->middlewarePaths($path, $route) as $candidate) {
            if (preg_match($regex, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the request path with and without the global prefix / version segment */
    private function middlewarePaths(string $path, ?Route $route): array
    {
        $paths = [Router::normalise($path)];
        $strip = static function (string $path, string $segment): ?string {
            $segment = '/' . trim($segment, '/');

            return $segment !== '/' && ($path === $segment || str_starts_with($path, $segment . '/')) ? Router::normalise(substr($path, strlen($segment))) : null;
        };
        $unprefixed = $paths[0];
        if ($this->globalPrefix !== '' && ! ($route?->meta['prefixExcluded'] ?? false) && ($p = $strip($unprefixed, $this->globalPrefix)) !== null) {
            $paths[] = $unprefixed = $p;
        }
        $versions = $route !== null ? array_filter([$route->meta['version'] ?? null]) : $this->versionSegments();
        foreach ($versions as $version) {
            if (($p = $strip($unprefixed, $version)) !== null) {
                $paths[] = $p;
                break;
            }
        }

        return $paths;
    }

    /** @return list<string> every URI version segment in use ('v1', 'v2'), for paths that matched no route */
    private function versionSegments(): array
    {
        return $this->versionSegments ??= $this->versioning === null ? [] : array_values(array_unique(array_filter(array_map(
            static fn (Route $r) => $r->meta['version'] ?? null,
            $this->router->routes(),
        ))));
    }

    /**
     * Tooling hook (`php nesto routes`): while set, listen() hands the configured app to it instead of serving,
     * so a command can require public/index.php and see the app exactly as configured there (prefix, versioning…).
     */
    public static function interceptListen(?\Closure $hook): void
    {
        self::$listenHook = $hook;
    }

    /** PHP-FPM / built-in server entrypoint; under a FrankenPHP worker it runs the long-lived request loop. */
    public function listen(): void
    {
        if (self::$listenHook !== null) {
            (self::$listenHook)($this);

            return;
        }
        if (FrankenPhpAdapter::isWorker()) {
            FrankenPhpAdapter::serve($this);

            return;
        }

        try {
            $this->init();
        } catch (\Throwable $e) {
            // boot errors (e.g. UnknownDependencyException): logged + JSON 500, not PHP's HTML error page
            BootErrorHandler::handle($e, isset($this->options['debug']) ? (bool) $this->options['debug'] : null);

            return;
        }
        $response = $this->handle(Request::fromGlobals());
        (new ResponseEmitter)->emit($response);
    }

    // ---- shutdown ----------------------------------------------------------------------

    public function close(?string $signal = null): void
    {
        $this->callHook(OnModuleDestroy::class, 'onModuleDestroy');
        $this->callHook(BeforeApplicationShutdown::class, 'beforeApplicationShutdown', $signal);
        $this->callHook(OnApplicationShutdown::class, 'onApplicationShutdown', $signal);
    }

    public function options(): array
    {
        return $this->options;
    }

    public function rootModule(): ModuleRef
    {
        return $this->root;
    }
}
