<?php

declare(strict_types=1);

namespace Nesto\Core;

use Nesto\Common\Provider;

/**
 * One node of the module graph. Holds the module's own providers/controllers/imports/exports
 * and knows which tokens are visible from inside it (own + imported exports + global modules).
 */
final class ModuleRef
{
    /** @var array<string, ProviderDefinition> token => definition */
    public array $providers = [];
    /** @var list<class-string> */
    public array $controllers = [];
    /** @var list<ModuleRef> */
    public array $imports = [];
    /** @var list<string> tokens or module classes */
    public array $exports = [];
    public bool $global = false;
    public ?object $instance = null;
    private ?array $visibleCache = null;

    public function __construct(
        public readonly string $class,
        private readonly Container $container,
    ) {}

    public function addProvider(Provider $provider): void
    {
        $key = $provider->provide;
        // APP_GUARD / APP_PIPE / ... may be registered many times; keep each under a unique key
        if (in_array($key, [Constants::APP_GUARD, Constants::APP_PIPE, Constants::APP_INTERCEPTOR, Constants::APP_FILTER], true)) {
            $key .= '#' . count($this->providers);
        }
        $this->providers[$key] = new ProviderDefinition($provider, $this, $key);
        $this->visibleCache = null;
    }

    /** Providers registered under an APP_* token. @return list<ProviderDefinition> */
    public function appProviders(string $token): array
    {
        $out = [];
        foreach ($this->providers as $key => $def) {
            if ($key === $token || str_starts_with($key, $token . '#')) {
                $out[] = $def;
            }
        }

        return $out;
    }

    public function addController(string $controller): void
    {
        if (! in_array($controller, $this->controllers, true)) {
            $this->controllers[] = $controller;
            // controllers are instantiated through the container like providers
            $this->addProvider(Provider::class($controller));
        }
    }

    public function addImport(ModuleRef $module): void
    {
        if (! in_array($module, $this->imports, true)) {
            $this->imports[] = $module;
            $this->visibleCache = null;
        }
    }

    public function addExports(array $exports): void
    {
        foreach ($exports as $e) {
            if (! in_array($e, $this->exports, true)) {
                $this->exports[] = $e;
            }
        }
        $this->visibleCache = null;
    }

    public function has(string $token): bool
    {
        return isset($this->providers[$token]);
    }

    /** Definitions this module makes available to importers (own exports + re-exported modules). */
    public function exported(): array
    {
        $out = [];
        foreach ($this->exports as $token) {
            if (isset($this->providers[$token])) {
                $out[$token] = $this->providers[$token];
            } else {
                foreach ($this->imports as $imp) {
                    if ($imp->class === $token) {
                        $out += $imp->exported();
                    }
                }
            }
        }

        return $out;
    }

    /** Resolve a token's definition as seen from inside this module. */
    public function find(string $token): ?ProviderDefinition
    {
        if (isset($this->providers[$token])) {
            return $this->providers[$token];
        }
        $visible = $this->visible();

        return $visible[$token] ?? null;
    }

    /** @return array<string, ProviderDefinition> */
    private function visible(): array
    {
        if ($this->visibleCache !== null) {
            return $this->visibleCache;
        }
        $visible = [];
        foreach ($this->imports as $imp) {
            $visible += $imp->exported();
        }
        foreach ($this->container->globalModules() as $g) {
            if ($g !== $this) {
                $visible += $g->exported();
            }
        }
        $visible += $this->container->coreProviders();

        return $this->visibleCache = $visible;
    }

    /** Shortcut for ModuleRef::get() in Nest (resolve within this module's scope). */
    public function get(string $token): mixed
    {
        return $this->container->resolve($token, $this);
    }

    public function resolve(string $token): mixed
    {
        return $this->container->resolve($token, $this);
    }

    public function invalidate(): void
    {
        $this->visibleCache = null;
    }
}
