<?php

declare(strict_types=1);

namespace Nesto\Core;

use Nesto\Common\Attributes\GlobalModule;
use Nesto\Common\Attributes\Module;
use Nesto\Common\Contracts\DynamicModuleFactory;
use Nesto\Common\DynamicModule;
use Nesto\Common\ForwardRef;
use Nesto\Common\Provider;
use ReflectionClass;

/** Walks the #[Module] graph from the root module and registers everything in the Container. */
final class ModuleScanner
{
    public function __construct(private readonly Container $container) {}

    public function scan(string|DynamicModule $root): ModuleRef
    {
        $ref = $this->visit($root);
        $this->validateExports();

        return $ref;
    }

    /**
     * Nest's export rule: a module can export only its own providers or modules it imports (re-export).
     * Anything else would silently export nothing, so fail at boot like Nest's UnknownExportException.
     */
    private function validateExports(): void
    {
        foreach ($this->container->modules() as $module) {
            if ($module->class === '__core__') {
                continue;
            }
            $imported = array_map(static fn (ModuleRef $m) => $m->class, $module->imports);
            foreach ($module->exports as $token) {
                if ($module->has($token) || in_array($token, $imported, true)) {
                    continue;
                }
                $hint = class_exists($token) && (new ReflectionClass($token))->getAttributes(Module::class) !== []
                    ? " Add $token to {$module->class}'s imports to re-export it."
                    : " Add it to {$module->class}'s providers, or export the module that provides it.";

                throw new UnknownExportException(
                    "Nesto cannot export \"$token\" from module {$module->class}: it is not one of its providers "
                    . 'and not a module it imports.' . $hint
                );
            }
        }
    }

    /**
     * Accepts: a class name, a DynamicModule, a ForwardRef, a module instance implementing
     * DynamicModuleFactory, or a [Class::class, 'forRoot', [...args]] tuple (attribute-friendly forRoot()).
     */
    private function visit(string|DynamicModule|ForwardRef|DynamicModuleFactory|array $module): ModuleRef
    {
        if ($module instanceof ForwardRef) {
            $module = $module->resolve();
        }
        if (is_array($module)) {
            [$class, $method, $args] = [$module[0], $module[1] ?? 'forRoot', $module[2] ?? []];
            $module = $class::$method(...$args);
        }
        if ($module instanceof DynamicModuleFactory) {
            $module = $module->toDynamicModule();
        }
        $dynamic = $module instanceof DynamicModule ? $module : null;
        $class = $dynamic ? $dynamic->module : $module;

        if (! class_exists($class)) {
            throw new ContainerException("Module class \"$class\" does not exist.");
        }

        $fresh = ! $this->container->hasModule($class);
        $ref = $this->container->module($class);
        $reflection = new ReflectionClass($class);
        $attr = $reflection->getAttributes(Module::class)[0] ?? null;
        $meta = $attr?->newInstance() ?? new Module;

        if ($fresh) {
            $ref->global = $reflection->getAttributes(GlobalModule::class) !== [];
            $this->apply($ref, $meta->imports, $meta->controllers, $meta->providers, $meta->exports);
        }
        if ($dynamic) {
            $ref->global = $ref->global || $dynamic->global;
            $this->apply($ref, $dynamic->imports, $dynamic->controllers, $dynamic->providers, $dynamic->exports);
        }

        return $ref;
    }

    private function apply(ModuleRef $ref, array $imports, array $controllers, array $providers, array $exports): void
    {
        foreach ($imports as $import) {
            $ref->addImport($this->visit($import));
        }
        foreach ($providers as $p) {
            $ref->addProvider(Provider::normalize($p));
        }
        foreach ($controllers as $c) {
            $ref->addController($c);
        }
        $ref->addExports(array_map($this->exportToken(...), $exports));
    }

    /** The token an `exports` entry stands for: a provider token, or the class of a (dynamic) module. */
    private function exportToken(mixed $export): string
    {
        if ($export instanceof ForwardRef) {
            $export = $export->resolve();
        }

        return match (true) {
            $export instanceof DynamicModule => $export->module,
            $export instanceof DynamicModuleFactory => $export->toDynamicModule()->module,
            $export instanceof Provider => $export->provide,
            is_array($export) => $export[0],
            default => $export,
        };
    }
}
