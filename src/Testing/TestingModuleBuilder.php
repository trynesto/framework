<?php

declare(strict_types=1);

namespace Nesto\Testing;

use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;
use Nesto\Core\Constants;
use Nesto\Core\Container;
use Nesto\Core\ModuleRef;
use Nesto\Core\NestoFactory;
use Nesto\Core\ProviderDefinition;

/**
 * Collects overrides and compiles a TestingModule. See Test::createTestingModule() for a full example.
 *
 * How overrides work: the module graph is scanned (nothing is instantiated yet), then every override is
 * injected as a ready-made instance into each module that declares the token (and core-wide for tokens
 * nobody declares, which is how `#[UseGuards(AuthGuard::class)]` references are resolved). Only then is
 * the application initialised, so the original providers are never constructed.
 */
final class TestingModuleBuilder
{
    /** @var array<string, array<string, mixed>> token => override spec */
    private array $providerOverrides = [];
    /** @var array<string, array<string, mixed>> enhancer class => override spec */
    private array $enhancerOverrides = [];
    /** @var array<string, mixed> NestoFactory options (e.g. debug) */
    private array $options = [];

    /** @param array{imports?: list<mixed>, controllers?: list<class-string>, providers?: list<mixed>, exports?: list<string>} $metadata */
    public function __construct(private readonly array $metadata) {}

    public function overrideProvider(string $token): OverrideBy
    {
        return new OverrideBy(function (array $spec) use ($token) {
            $this->providerOverrides[$token] = $spec;

            return $this;
        });
    }

    /** @param class-string $guard */
    public function overrideGuard(string $guard): OverrideBy
    {
        return $this->overrideEnhancer($guard);
    }

    /** @param class-string $pipe */
    public function overridePipe(string $pipe): OverrideBy
    {
        return $this->overrideEnhancer($pipe);
    }

    /** @param class-string $interceptor */
    public function overrideInterceptor(string $interceptor): OverrideBy
    {
        return $this->overrideEnhancer($interceptor);
    }

    /** @param class-string $filter */
    public function overrideFilter(string $filter): OverrideBy
    {
        return $this->overrideEnhancer($filter);
    }

    /** Options passed to NestoFactory::create() (e.g. ['debug' => true]). */
    public function withOptions(array $options): self
    {
        $this->options = $options + $this->options;

        return $this;
    }

    private function overrideEnhancer(string $class): OverrideBy
    {
        return new OverrideBy(function (array $spec) use ($class) {
            $this->enhancerOverrides[$class] = $spec;

            return $this;
        });
    }

    public function compile(): TestingModule
    {
        $root = new DynamicModule(
            RootTestModule::class,
            imports: $this->metadata['imports'] ?? [],
            controllers: $this->metadata['controllers'] ?? [],
            providers: $this->metadata['providers'] ?? [],
            exports: $this->metadata['exports'] ?? [],
        );
        $app = NestoFactory::create($root, $this->options);
        $container = $app->container();

        foreach ($this->providerOverrides as $token => $spec) {
            $owners = $this->modulesDeclaring($container, $token);
            $value = $this->build($spec, $container, $owners[0] ?? $app->rootModule());
            if (! is_object($value)) {
                // scalars/arrays: swap the definition for a value provider
                $this->replaceWithValue($container, $token, $value, $owners);
                continue;
            }
            if ($owners === []) {
                $container->instance($token, $value);
            }
            foreach ($owners as $module) {
                $container->instance($token, $value, $module);
            }
        }

        foreach ($this->enhancerOverrides as $class => $spec) {
            $owners = $this->modulesDeclaring($container, $class);
            $value = $this->build($spec, $container, $owners[0] ?? $app->rootModule());
            if (! is_object($value)) {
                throw new \InvalidArgumentException("Override for $class must produce an object.");
            }
            // 1. referenced by class name in #[UseGuards]/#[UsePipes]/... → resolved core-wide with strict: false
            $container->instance($class, $value);
            // 2. registered as a module provider
            foreach ($owners as $module) {
                $container->instance($class, $value, $module);
            }
            // 3. registered as APP_GUARD / APP_PIPE / ... with useClass
            foreach ($container->modules() as $module) {
                foreach ($module->providers as $key => $def) {
                    if ($def->provider->useClass === $class && $this->isAppToken($def->token())) {
                        $module->providers[$key] = new ProviderDefinition((new Provider($def->token()))->useValue($value), $module);
                    }
                }
                $module->invalidate();
            }
        }

        return new TestingModule($app);
    }

    /** @return list<ModuleRef> */
    private function modulesDeclaring(Container $container, string $token): array
    {
        $out = [];
        foreach ($container->modules() as $module) {
            if ($module->class !== '__core__' && $module->has($token)) {
                $out[] = $module;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $spec */
    private function build(array $spec, Container $container, ModuleRef $module): mixed
    {
        return match ($spec['type']) {
            'value' => $spec['value'],
            'class' => $container->instantiate($spec['class'], $module),
            'factory' => $spec['inject'] === []
                ? $container->call($spec['factory'], [], $module)
                : ($spec['factory'])(...array_map(fn (string $t) => $container->resolve($t, $module, strict: false), $spec['inject'])),
        };
    }

    /** @param list<ModuleRef> $owners */
    private function replaceWithValue(Container $container, string $token, mixed $value, array $owners): void
    {
        $provider = (new Provider($token))->useValue($value);
        if ($owners === []) {
            $container->core($provider);

            return;
        }
        foreach ($owners as $module) {
            $module->providers[$token] = new ProviderDefinition($provider, $module);
        }
        foreach ($container->modules() as $m) {
            $m->invalidate();
        }
    }

    private function isAppToken(string $token): bool
    {
        return in_array($token, [Constants::APP_GUARD, Constants::APP_PIPE, Constants::APP_INTERCEPTOR, Constants::APP_FILTER], true);
    }
}
