<?php

declare(strict_types=1);

namespace Nesto\Schedule;

use Nesto\Common\Contracts\OnApplicationBootstrap;
use Nesto\Common\Scope;
use Nesto\Core\Container;
use Nesto\Schedule\Attributes\Cron;
use Nesto\Schedule\Attributes\Interval;
use Nesto\Schedule\Attributes\Timeout;
use ReflectionClass;
use ReflectionMethod;

/**
 * On bootstrap, scans every module's class providers for #[Cron], #[Interval] and #[Timeout]
 * methods and registers them in the SchedulerRegistry bound to the provider instance.
 * Unnamed jobs are registered as "Fully\Qualified\Class::method" (suffixed "#2", "#3" when repeated).
 */
final class ScheduleExplorer implements OnApplicationBootstrap
{
    private bool $explored = false;

    public function __construct(
        private readonly Container $container,
        private readonly SchedulerRegistry $registry,
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
                $instance = null;
                foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    $crons = $method->getAttributes(Cron::class);
                    $intervals = $method->getAttributes(Interval::class);
                    $timeouts = $method->getAttributes(Timeout::class);
                    if ($crons === [] && $intervals === [] && $timeouts === []) {
                        continue;
                    }
                    $instance ??= $module->resolve((string) $token);
                    $callable = [$instance, $method->getName()];
                    $base = $class . '::' . $method->getName();

                    foreach ($crons as $i => $attr) {
                        /** @var Cron $cron */
                        $cron = $attr->newInstance();
                        $this->registry->addCronJob($cron->name ?? self::name($base, $i), $cron->expression, $callable, $cron->timeZone);
                    }
                    foreach ($intervals as $i => $attr) {
                        /** @var Interval $interval */
                        $interval = $attr->newInstance();
                        $this->registry->addInterval($interval->name ?? self::name($base, $i), $interval->milliseconds, $callable);
                    }
                    foreach ($timeouts as $i => $attr) {
                        /** @var Timeout $timeout */
                        $timeout = $attr->newInstance();
                        $this->registry->addTimeout($timeout->name ?? self::name($base, $i), $timeout->milliseconds, $callable);
                    }
                }
            }
        }
    }

    private static function name(string $base, int $index): string
    {
        return $index === 0 ? $base : $base . '#' . ($index + 1);
    }
}
