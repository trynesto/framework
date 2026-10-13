<?php

declare(strict_types=1);

namespace Nesto\Auth;

use Nesto\Auth\Contracts\Strategy;
use Nesto\Common\Contracts\OnModuleInit;
use Nesto\Common\Scope;
use Nesto\Core\Container;

/**
 * Holds every Strategy known to the application, indexed by name().
 *
 * Provided by AuthModule. On module init it walks the container and registers every provider that is
 * (or builds) a Strategy, so strategies only need to be listed in some module's `providers`.
 * The last initialised registry is also reachable statically (StrategyRegistry::current()) so a guard created
 * with `new AuthGuard('jwt')` inside an attribute — where no DI is possible — can still find it.
 *
 *   $registry->get('jwt')->authenticate($request);
 *   $registry->register(new MyStrategy);           // manual registration
 */
final class StrategyRegistry implements OnModuleInit
{
    private static ?self $current = null;

    /** @var array<string, Strategy> */
    private array $strategies = [];
    private bool $discovered = false;

    public function __construct(
        private readonly ?Container $container = null,
        private ?string $defaultStrategy = null,
    ) {
        self::$current = $this;
    }

    /** The registry of the most recently initialised application (null before AuthModule was set up). */
    public static function current(): ?self
    {
        return self::$current;
    }

    public function onModuleInit(): void
    {
        $this->discover();
        self::$current = $this;
    }

    public function register(Strategy $strategy): self
    {
        $this->strategies[$strategy->name()] = $strategy;

        return $this;
    }

    public function has(string $name): bool
    {
        $this->discover();

        return isset($this->strategies[$name]);
    }

    public function get(string $name): Strategy
    {
        $this->discover();
        if (! isset($this->strategies[$name])) {
            throw new \LogicException(sprintf(
                'Unknown authentication strategy "%s". Known strategies: %s. Register a provider implementing %s with name() === "%s".',
                $name,
                $this->strategies === [] ? '(none)' : implode(', ', array_keys($this->strategies)),
                Strategy::class,
                $name,
            ));
        }

        return $this->strategies[$name];
    }

    /** @return list<string> */
    public function names(): array
    {
        $this->discover();

        return array_keys($this->strategies);
    }

    public function defaultStrategy(): ?string
    {
        if ($this->defaultStrategy !== null) {
            return $this->defaultStrategy;
        }
        $names = $this->names();

        return count($names) === 1 ? $names[0] : null;
    }

    public function setDefaultStrategy(?string $name): self
    {
        $this->defaultStrategy = $name;

        return $this;
    }

    /** Scan every module's providers for strategies (idempotent; call again after adding modules). */
    public function discover(bool $force = false): void
    {
        if (($this->discovered && ! $force) || $this->container === null) {
            return;
        }
        $this->discovered = true;

        foreach ($this->container->modules() as $module) {
            if ($module->class === '__core__') {
                continue;
            }
            foreach ($module->providers as $key => $def) {
                $provider = $def->provider;
                if ($provider->scope !== Scope::DEFAULT) {
                    continue;
                }
                if ($provider->hasValue) {
                    if ($provider->useValue instanceof Strategy) {
                        $this->register($provider->useValue);
                    }
                    continue;
                }
                if ($provider->useExisting !== null) {
                    continue;
                }
                $class = $provider->useClass ?? $def->token();
                if ($provider->useFactory === null && ! (class_exists($class) && is_a($class, Strategy::class, true))) {
                    continue;
                }
                if ($provider->useFactory !== null && ! $this->factoryMayProduceStrategy($provider->useFactory)) {
                    continue;
                }
                $instance = $this->container->resolve((string) $key, $module);
                if ($instance instanceof Strategy) {
                    $this->register($instance);
                }
            }
        }
    }

    /** Only resolve factory providers whose declared return type is (or may be) a Strategy. */
    private function factoryMayProduceStrategy(\Closure|array|string $factory): bool
    {
        try {
            $ref = match (true) {
                $factory instanceof \Closure => new \ReflectionFunction($factory),
                is_array($factory) => new \ReflectionMethod($factory[0], $factory[1]),
                class_exists($factory) => new \ReflectionMethod($factory, '__invoke'),
                default => null,
            };
        } catch (\ReflectionException) {
            return false;
        }
        if ($ref === null) {
            return false;
        }
        $type = $ref->getReturnType();
        if (! $type instanceof \ReflectionNamedType) {
            return false; // untyped / union → do not guess
        }
        $name = $type->getName();

        return $name === 'object' || (class_exists($name) || interface_exists($name)) && is_a($name, Strategy::class, true);
    }
}
