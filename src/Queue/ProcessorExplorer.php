<?php

declare(strict_types=1);

namespace Nesto\Queue;

use Nesto\Common\Contracts\OnApplicationBootstrap;
use Nesto\Common\Scope;
use Nesto\Core\Container;
use Nesto\Queue\Attributes\OnQueueActive;
use Nesto\Queue\Attributes\OnQueueCompleted;
use Nesto\Queue\Attributes\OnQueueFailed;
use Nesto\Queue\Attributes\Process;
use Nesto\Queue\Attributes\Processor;
use ReflectionClass;
use ReflectionMethod;

/**
 * On bootstrap, finds every #[Processor] provider and registers its #[Process] handlers and
 * #[OnQueueActive] / #[OnQueueCompleted] / #[OnQueueFailed] hooks in the ProcessorRegistry.
 */
final class ProcessorExplorer implements OnApplicationBootstrap
{
    private bool $explored = false;

    public function __construct(
        private readonly Container $container,
        private readonly ProcessorRegistry $registry,
    ) {
    }

    public function onApplicationBootstrap(): void
    {
        $this->explore();
    }

    /** Scan the module graph (idempotent). */
    public function explore(): void
    {
        if ($this->explored) {
            return;
        }
        $this->explored = true;

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
                $ref = new ReflectionClass($class);
                $processor = $ref->getAttributes(Processor::class)[0] ?? null;
                if ($processor === null) {
                    continue;
                }
                $queue = $processor->newInstance()->queue;
                $instance = $module->resolve((string) $token);

                foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    $callable = [$instance, $method->getName()];
                    foreach ($method->getAttributes(Process::class) as $attr) {
                        $this->registry->register($queue, $attr->newInstance()->name, $callable);
                    }
                    if ($method->getAttributes(OnQueueActive::class) !== []) {
                        $this->registry->on($queue, 'active', $callable);
                    }
                    if ($method->getAttributes(OnQueueCompleted::class) !== []) {
                        $this->registry->on($queue, 'completed', $callable);
                    }
                    if ($method->getAttributes(OnQueueFailed::class) !== []) {
                        $this->registry->on($queue, 'failed', $callable);
                    }
                }
            }
        }
    }
}
