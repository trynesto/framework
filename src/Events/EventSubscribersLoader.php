<?php

declare(strict_types=1);

namespace Nesto\Events;

use Nesto\Common\Contracts\OnApplicationBootstrap;
use Nesto\Common\Scope;
use Nesto\Core\Container;
use Nesto\Events\Attributes\OnEvent;
use ReflectionClass;
use ReflectionMethod;

/**
 * Walks every module's class providers (and controllers) looking for #[OnEvent] methods and
 * registers them on the EventEmitter, bound to the resolved singleton instance.
 * Runs automatically on application bootstrap; call load() yourself in bare container setups.
 *
 * Request- and transient-scoped providers are skipped (there is no instance to bind to).
 */
final class EventSubscribersLoader implements OnApplicationBootstrap
{
    private bool $loaded = false;
    /** @var list<array{event: string, class: class-string, method: string, priority: int}> */
    private array $registered = [];

    public function __construct(
        private readonly Container $container,
        private readonly EventEmitter $emitter,
    ) {
    }

    public function onApplicationBootstrap(): void
    {
        $this->load();
    }

    /** Scan the module graph (idempotent). */
    public function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        foreach ($this->container->modules() as $module) {
            if ($module->class === '__core__') {
                continue;
            }
            foreach ($module->providers as $token => $def) {
                $provider = $def->provider;
                $class = $provider->useClass;
                if ($class === null || $provider->hasValue || $provider->useFactory !== null || $provider->useExisting !== null) {
                    continue;
                }
                if ($def->scope() !== Scope::DEFAULT || ! class_exists($class)) {
                    continue;
                }
                $instance = null;
                foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    $attrs = $method->getAttributes(OnEvent::class);
                    if ($attrs === []) {
                        continue;
                    }
                    $instance ??= $module->resolve((string) $token);
                    foreach ($attrs as $attr) {
                        /** @var OnEvent $on */
                        $on = $attr->newInstance();
                        $this->emitter->on($on->event, [$instance, $method->getName()], $on->priority);
                        $this->registered[] = ['event' => $on->event, 'class' => $class, 'method' => $method->getName(), 'priority' => $on->priority];
                    }
                }
            }
        }
    }

    /** What was discovered (for debugging / `php nesto events:list`). @return list<array{event: string, class: class-string, method: string, priority: int}> */
    public function registered(): array
    {
        return $this->registered;
    }
}
