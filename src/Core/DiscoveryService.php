<?php

declare(strict_types=1);

namespace Nesto\Core;

use Nesto\Common\Scope;
use ReflectionClass;
use ReflectionMethod;

/**
 * Nest's DiscoveryService: find providers / controllers and their methods carrying an attribute.
 *
 *   foreach ($discovery->methodsWithAttribute(OnEvent::class) as [$instance, $method, $attribute]) { ... }
 */
final class DiscoveryService
{
    public function __construct(private readonly Container $container) {}

    /** @return list<array{0: object, 1: ReflectionClass, 2: ModuleRef}> every instantiable class provider (+ controllers) with its instance */
    public function providers(bool $includeControllers = true): array
    {
        $out = [];
        foreach ($this->container->modules() as $module) {
            if ($module->class === '__core__') {
                continue;
            }
            foreach ($module->providers as $key => $def) {
                $class = $def->provider->useClass;
                if ($class === null || $def->scope() !== Scope::DEFAULT || ! class_exists($class)) {
                    continue;
                }
                if (! $includeControllers && in_array($class, $module->controllers, true)) {
                    continue;
                }
                try {
                    $instance = $this->container->resolve((string) $key, $module);
                } catch (OutsideRequestScopeException) {
                    continue;
                }
                if (is_object($instance)) {
                    $out[] = [$instance, new ReflectionClass($instance), $module];
                }
            }
        }

        return $out;
    }

    /** @return list<array{0: object, 1: ReflectionMethod, 2: object}> [instance, method, attribute instance] */
    public function methodsWithAttribute(string $attribute): array
    {
        $out = [];
        foreach ($this->providers() as [$instance, $class]) {
            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes($attribute, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
                    $out[] = [$instance, $method, $attr->newInstance()];
                }
            }
        }

        return $out;
    }

    /** @return list<array{0: object, 1: object}> [instance, attribute instance] */
    public function classesWithAttribute(string $attribute): array
    {
        $out = [];
        foreach ($this->providers() as [$instance, $class]) {
            foreach ($class->getAttributes($attribute, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
                $out[] = [$instance, $attr->newInstance()];
            }
        }

        return $out;
    }
}
