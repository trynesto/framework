<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Core\NestoApplication;

/**
 * Helpers for commands that depend on optional packages (ORM, Queue, Schedule).
 * Everything is checked with class_exists / method_exists so a missing package prints a hint instead of a fatal error.
 */
trait InteractsWithPackages
{
    /** Resolve $class from the booted app, or print why it is unavailable and return null. */
    protected function package(string $class, string $package, string $moduleHint): ?object
    {
        if (! class_exists($class)) {
            $this->error("The $package package is not installed (class $class not found).");
            $this->comment("Make sure the Nesto $package package is part of your installation.");

            return null;
        }
        $app = $this->bootApp();
        if ($app === null) {
            return null;
        }
        if (! $this->isProvided($app, $class)) {
            $this->error("$package is installed but not configured in this application.");
            $this->comment("Import $moduleHint in your root module.");

            return null;
        }
        try {
            $instance = $app->get($class);
        } catch (\Throwable $e) {
            $this->error("Unable to resolve $class: " . $e->getMessage());

            return null;
        }

        return is_object($instance) ? $instance : null;
    }

    /** True when some module (or the core) declares $class as a provider — Container::has() also accepts any existing class. */
    protected function isProvided(NestoApplication $app, string $class): bool
    {
        $container = $app->container();
        if (isset($container->coreProviders()[$class])) {
            return true;
        }
        foreach ($container->modules() as $module) {
            if ($module->has($class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The ORM Migrator, extended with the migrations directory from nesto.config.php when the
     * DataSource options do not already include it.
     */
    protected function migrator(object $dataSource): ?object
    {
        if (! method_exists($dataSource, 'migrator')) {
            return null;
        }
        // One Migrator per DataSource per process: the ORM registers migration classes when their file is
        // first required, so a second Migrator built in the same process would not see them again.
        $cached = MigratorCache::get($dataSource);
        if ($cached !== null) {
            return $cached;
        }
        $migrator = MigratorCache::put($dataSource, $dataSource->migrator());
        $dir = $this->migrationsDir();
        if (! is_dir($dir) || ! method_exists($migrator, 'add')) {
            return $migrator;
        }
        $configured = method_exists($dataSource, 'getOptions') ? (array) ($dataSource->getOptions()['migrations'] ?? []) : [];
        foreach ($configured as $entry) {
            if (is_string($entry) && is_dir($entry) && realpath($entry) === realpath($dir)) {
                return $migrator;
            }
        }
        $migrator->add($dir);

        return $migrator;
    }

    protected function bootApp(): ?NestoApplication
    {
        try {
            return $this->context()->app();
        } catch (\Throwable $e) {
            $this->error('Could not boot the application: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Call $method on $object passing only the named arguments its signature declares
     * (tolerates API differences between package versions).
     *
     * @param array<string, mixed> $args
     */
    protected function callWithKnownArgs(object $object, string $method, array $args = []): mixed
    {
        if (! method_exists($object, $method)) {
            throw new \RuntimeException(sprintf('%s::%s() does not exist.', $object::class, $method));
        }
        $ref = new \ReflectionMethod($object, $method);
        $pass = [];
        foreach ($ref->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $args)) {
                $pass[$name] = $args[$name];
            }
        }

        return $object->{$method}(...$pass);
    }

    protected function firstExistingMethod(object $object, string ...$candidates): ?string
    {
        foreach ($candidates as $m) {
            if (method_exists($object, $m)) {
                return $m;
            }
        }

        return null;
    }

    protected function migrationsDir(): string
    {
        return $this->context()->path((string) $this->context()->config('migrations'));
    }
}
