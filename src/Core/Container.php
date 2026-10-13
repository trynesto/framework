<?php

declare(strict_types=1);

namespace Nesto\Core;

use Nesto\Common\Attributes\Inject;
use Nesto\Common\Attributes\Optional;
use Nesto\Common\Provider;
use Nesto\Common\Scope;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * The injector. PSR-11 compatible. Understands module encapsulation (through ModuleRef),
 * the three Nest scopes, #[Inject], #[Optional], factory/value/class/existing providers and
 * detects circular dependencies.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, ModuleRef> */
    private array $modules = [];
    /** @var array<string, ProviderDefinition> providers visible everywhere (Request, Reflector, app...) */
    private array $core = [];
    /** @var array<string, mixed> singleton instances (objects, or factory results) keyed by "moduleClass#token" */
    private array $singletons = [];
    /** @var array<string, object> request-scoped instances, cleared per request */
    private array $requestInstances = [];
    /** @var array<string, bool> tokens whose instance graph touches a REQUEST-scoped provider (scope bubbling) */
    private array $bubbled = [];
    /** @var array<string, true> */
    private array $resolving = [];
    /** @var array<class-string, list<array{name:string,token:?string,type:?string,optional:bool,default:mixed,hasDefault:bool,builtin:bool}>> */
    private array $ctorCache = [];
    private bool $requestTouched = false;

    // ---- registration -------------------------------------------------------

    public function module(string $class): ModuleRef
    {
        return $this->modules[$class] ??= new ModuleRef($class, $this);
    }

    public function hasModule(string $class): bool
    {
        return isset($this->modules[$class]);
    }

    /** @return array<string, ModuleRef> */
    public function modules(): array
    {
        return $this->modules;
    }

    /** @return list<ModuleRef> */
    public function globalModules(): array
    {
        return array_values(array_filter($this->modules, fn (ModuleRef $m) => $m->global));
    }

    /** Register a provider visible from every module (framework services). */
    public function core(Provider|string $provider, mixed $value = null): void
    {
        if (is_string($provider)) {
            $provider = (new Provider($provider))->useValue($value);
        }
        $this->core[$provider->provide] = new ProviderDefinition($provider, $this->module('__core__'));
        foreach ($this->modules as $m) {
            $m->invalidate();
        }
    }

    /** @return array<string, ProviderDefinition> */
    public function coreProviders(): array
    {
        return $this->core;
    }

    // ---- PSR-11 -------------------------------------------------------------

    public function get(string $id): mixed
    {
        return $this->resolve($id, null);
    }

    public function has(string $id): bool
    {
        if (isset($this->core[$id])) {
            return true;
        }
        foreach ($this->modules as $m) {
            if ($m->has($id)) {
                return true;
            }
        }

        return class_exists($id);
    }

    /** Tokens that only exist once a root module is imported; the generic hint would mislead. */
    private const ROOT_MODULE_HINTS = [
        'Nesto\\Orm\\DataSource' => "The ORM has no connection: import `new DatabaseModule()` in AppModule (it reads ORM_DRIVER=orm and DB_CONNECTION / DB_DATABASE… from .env and config/database.php), or the root module directly, e.g. #[Module(imports: [new OrmModule(type: 'sqlite', database: 'database/app.sqlite', synchronize: true)])].",
        'Nesto\\Config\\ConfigService' => 'Import the config module in AppModule: #[Module(imports: [new ConfigModule(isGlobal: true)])].',
    ];

    // ---- resolution -----------------------------------------------------------

    /**
     * Resolve a token. $from is the module asking (enforces encapsulation like Nest).
     * With $from = null the lookup is global (used by app.get(), tests, CLI).
     */
    public function resolve(string $token, ?ModuleRef $from = null, bool $strict = true): mixed
    {
        $def = $from ? $from->find($token) : $this->findAnywhere($token);

        if ($def === null) {
            if (class_exists($token) && ! $strict) {
                // Not registered anywhere: autowire ad-hoc (used for pipes/guards/filters given as class names).
                return $this->instantiate($token, $from ?? $this->module('__core__'));
            }
            throw new NotFoundException($this->notFoundMessage($token, $from));
        }

        return $this->resolveDefinition($def, $from);
    }

    private function resolveDefinition(ProviderDefinition $def, ?ModuleRef $from): mixed
    {
        $provider = $def->provider;
        $key = $def->module->class . '#' . $def->key;

        if ($provider->useExisting !== null) {
            return $this->resolve($provider->useExisting, $def->module);
        }
        if ($provider->hasValue) {
            return $provider->useValue;
        }

        $scope = $provider->scope;
        if ($scope === Scope::DEFAULT && isset($this->singletons[$key])) {
            return $this->singletons[$key];
        }
        if ($scope === Scope::DEFAULT && isset($this->bubbled[$key]) && isset($this->requestInstances[$key])) {
            $this->requestTouched = true;

            return $this->requestInstances[$key];
        }
        if ($scope === Scope::REQUEST && isset($this->requestInstances[$key])) {
            $this->requestTouched = true;

            return $this->requestInstances[$key];
        }
        if ($scope === Scope::REQUEST && $def->module->class === '__core__' && $provider->useClass === null && $provider->useFactory === null) {
            throw new OutsideRequestScopeException("\"{$def->token()}\" is request-scoped and no request is being handled right now.");
        }

        if (isset($this->resolving[$key])) {
            throw new CircularDependencyException(
                'Circular dependency detected while resolving "' . $def->token() . '": ' . implode(' -> ', array_keys($this->resolving)) . ' -> ' . $def->token()
            );
        }
        $this->resolving[$key] = true;
        $touchedBefore = $this->requestTouched;
        $this->requestTouched = $scope === Scope::REQUEST;

        try {
            if ($provider->useFactory !== null) {
                $instance = $this->runFactory($provider->useFactory, $provider->inject, $def->module);
            } else {
                $instance = $this->instantiate($provider->useClass ?? $def->token(), $def->module);
            }
        } finally {
            unset($this->resolving[$key]);
        }

        $touched = $this->requestTouched;
        $this->requestTouched = $touchedBefore || $touched;

        if ($scope === Scope::REQUEST || ($scope === Scope::DEFAULT && $touched)) {
            // request-scoped, or a default provider that depends on one (scope bubbling)
            $this->bubbled[$key] = true;
            $this->requestInstances[$key] = $instance;
        } elseif ($scope === Scope::DEFAULT) {
            $this->singletons[$key] = $instance;
        }

        return $instance;
    }

    /** Factories: Closure, [Class, 'staticMethod'], or an invokable class name; without `inject` their parameters are autowired by type. */
    private function runFactory(\Closure|array|string $factory, array $inject, ModuleRef $module): mixed
    {
        if (is_string($factory) && class_exists($factory)) {
            $factory = $this->instantiate($factory, $module);
            if ($inject === []) {
                return $this->call($factory, [], $module);
            }
        }
        if ($inject === []) {
            return $this->call($factory, [], $module);
        }
        $args = array_map(fn (string $t) => $this->resolve($t, $module), $inject);

        return $factory(...$args);
    }

    /** Build an object by reflecting its constructor; dependencies resolved from $module's view. */
    public function instantiate(string $class, ModuleRef $module): object
    {
        if (! class_exists($class)) {
            throw new NotFoundException("Class \"$class\" does not exist.");
        }
        $args = [];
        foreach ($this->constructorParams($class) as $p) {
            $args[] = $this->resolveParam($p, $class, $module);
        }
        $instance = new $class(...$args);
        $this->injectProperties($instance, $module);

        return $instance;
    }

    /** Call any callable/method, resolving its typed parameters from the container (like app.get() for each). */
    public function call(callable|array $callable, array $named = [], ?ModuleRef $module = null): mixed
    {
        $ref = is_array($callable)
            ? new \ReflectionMethod($callable[0], $callable[1])
            : (is_object($callable) && ! $callable instanceof \Closure
                ? new \ReflectionMethod($callable, '__invoke')
                : new \ReflectionFunction(\Closure::fromCallable($callable)));
        $args = [];
        foreach ($ref->getParameters() as $param) {
            if (array_key_exists($param->getName(), $named)) {
                $args[] = $named[$param->getName()];
                continue;
            }
            $args[] = $this->resolveParam($this->describeParam($param), $ref->getName(), $module ?? $this->module('__core__'));
        }

        return $callable(...$args);
    }

    private function resolveParam(array $p, string $owner, ModuleRef $module): mixed
    {
        $token = $p['token'] ?? ($p['builtin'] ? null : $p['type']);
        if ($token !== null) {
            $def = $module->find($token);
            if ($def !== null) {
                return $this->resolveDefinition($def, $module);
            }
            if ($p['optional'] || $p['hasDefault']) {
                // #[Optional], `?Foo $foo = null` or `Foo $foo = new Foo()`: fall back to the default
                return $p['hasDefault'] ? $this->defaultValue($p) : null;
            }
            throw new UnknownDependencyException($this->notFoundMessage($token, $module, $owner, $p['name']));
        }
        if ($p['hasDefault']) {
            return $this->defaultValue($p);
        }
        if ($p['optional']) {
            return null;
        }
        throw new UnknownDependencyException(
            "Nesto can't resolve parameter \${$p['name']} of $owner: it has no class type and no #[Inject] token."
        );
    }

    /** Evaluated on every call so `Foo $foo = new Foo()` gives each instance its own object, as PHP does. */
    private function defaultValue(array $p): mixed
    {
        return $p['default'] instanceof ReflectionParameter ? $p['default']->getDefaultValue() : $p['default'];
    }

    /** The module that lists $token in its providers. */
    private function declaringModule(string $token): ?ModuleRef
    {
        foreach ($this->modules as $m) {
            if ($m->class !== '__core__' && $m->has($token)) {
                return $m;
            }
        }

        return null;
    }

    private function injectProperties(object $instance, ModuleRef $module): void
    {
        $ref = new ReflectionClass($instance);
        foreach ($ref->getProperties() as $prop) {
            $attr = $prop->getAttributes(Inject::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
            if ($attr === null || $prop->isPromoted() || $prop->isInitialized($instance)) {
                continue; // promoted params are handled by the constructor
            }
            $token = $attr->newInstance()->token;
            $optional = $prop->getAttributes(Optional::class) !== [];
            $def = $module->find($token);
            if ($def === null) {
                if ($optional) {
                    continue;
                }
                throw new UnknownDependencyException($this->notFoundMessage($token, $module, $ref->getName(), $prop->getName()));
            }
            $prop->setValue($instance, $this->resolveDefinition($def, $module));
        }
    }

    /** @return list<array{name:string,token:?string,type:?string,optional:bool,default:mixed,hasDefault:bool,builtin:bool}> */
    public function constructorParams(string $class): array
    {
        if (isset($this->ctorCache[$class])) {
            return $this->ctorCache[$class];
        }
        $ctor = (new ReflectionClass($class))->getConstructor();
        $params = [];
        if ($ctor !== null) {
            foreach ($ctor->getParameters() as $param) {
                $params[] = $this->describeParam($param);
            }
        }

        return $this->ctorCache[$class] = $params;
    }

    private function describeParam(ReflectionParameter $param): array
    {
        $type = $param->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
        $builtin = $type instanceof ReflectionNamedType ? $type->isBuiltin() : true;
        if ($typeName === 'self') {
            $typeName = $param->getDeclaringClass()?->getName();
        }
        $inject = $param->getAttributes(Inject::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;

        return [
            'name' => $param->getName(),
            'token' => $inject ? $inject->newInstance()->token : null,
            'type' => $typeName,
            'builtin' => $builtin,
            'optional' => $param->getAttributes(Optional::class) !== [] || ($type?->allowsNull() && $param->isDefaultValueAvailable()),
            'hasDefault' => $param->isDefaultValueAvailable(),
            // object defaults (`= new Foo()`) are kept as the reflection and re-evaluated per call by defaultValue()
            'default' => $param->isDefaultValueAvailable() ? (is_object($default = $param->getDefaultValue()) ? $param : $default) : null,
        ];
    }

    private function findAnywhere(string $token): ?ProviderDefinition
    {
        if (isset($this->core[$token])) {
            return $this->core[$token];
        }
        foreach ($this->modules as $m) {
            if ($m->has($token)) {
                return $m->providers[$token];
            }
        }

        return null;
    }

    private function notFoundMessage(string $token, ?ModuleRef $from, ?string $owner = null, ?string $param = null): string
    {
        $where = $from ? " in module {$from->class}" : '';
        $what = $owner ? "argument \$$param of $owner" : "token \"$token\"";
        $hint = '';
        $declaring = $from ? $this->declaringModule($token) : null;
        if ($declaring !== null && ! isset($declaring->exported()[$token])) {
            $m = $declaring->class;
            $hint = " \"$token\" is a provider of module $m but $m does not export it — add it to $m's exports and import $m.";
        } elseif ($from && $this->findAnywhere($token) !== null) {
            $declared = $this->findAnywhere($token)->module->class;
            $hint = " \"$token\" is provided by $declared — import that module and make sure it exports \"$token\".";
        } elseif (isset(self::ROOT_MODULE_HINTS[$token])) {
            $hint = ' ' . self::ROOT_MODULE_HINTS[$token];
        } else {
            $hint = $from
                ? " Make sure \"$token\" is listed in the providers of this module, or exported by a module it imports."
                : " No module lists \"$token\" in its providers. To build an unregistered class, use instantiate().";
        }

        return "Nesto can't resolve $what$where.$hint";
    }

    // ---- request lifecycle ----------------------------------------------------

    /** Called by the application at the end of each request. */
    public function endRequest(): void
    {
        $this->requestInstances = [];
    }

    /** Override or inject an instance directly (tests, APP_* registration, REQUEST token). */
    public function instance(string $token, object $instance, ?ModuleRef $module = null): void
    {
        $module ??= $this->module('__core__');
        $key = $module->class . '#' . $token;
        if ($module->class === '__core__') {
            $this->core[$token] = new ProviderDefinition(Provider::class($token), $module);
            foreach ($this->modules as $m) {
                $m->invalidate();
            }
        } elseif (! $module->has($token)) {
            $module->addProvider(Provider::class($token));
        } elseif ($this->bypassesSingletons($module->providers[$token]->provider)) {
            // useValue / useExisting / REQUEST / TRANSIENT definitions never consult the singleton map:
            // re-register the token as a plain singleton so the injected instance wins (test overrides).
            $module->providers[$token] = new ProviderDefinition(Provider::class($token), $module);
            foreach ($this->modules as $m) {
                $m->invalidate();
            }
        }
        unset($this->requestInstances[$key], $this->bubbled[$key]);
        $this->singletons[$key] = $instance;
    }

    private function bypassesSingletons(Provider $provider): bool
    {
        return $provider->hasValue || $provider->useExisting !== null || $provider->scope !== Scope::DEFAULT;
    }

    /** Declare a request-scoped core token whose instance is supplied per request via requestInstance(). */
    public function requestToken(string $token): void
    {
        if (! isset($this->core[$token])) {
            $p = (new Provider($token))->scope(Scope::REQUEST);
            $this->core[$token] = new ProviderDefinition($p, $this->module('__core__'));
            foreach ($this->modules as $m) {
                $m->invalidate();
            }
        }
    }

    /** Request-scoped instance (e.g. the current Request) — cleared by endRequest(). */
    public function requestInstance(string $token, object $instance): void
    {
        $this->requestToken($token);
        $this->requestInstances['__core__#' . $token] = $instance;
    }

    /** All instantiated singletons (for lifecycle hooks). @return list<object> */
    public function instances(): array
    {
        return array_values(array_filter($this->singletons, 'is_object'));
    }

    public function forget(string $token): void
    {
        foreach (array_keys($this->singletons) as $key) {
            if (str_ends_with($key, '#' . $token)) {
                unset($this->singletons[$key]);
            }
        }
    }
}
