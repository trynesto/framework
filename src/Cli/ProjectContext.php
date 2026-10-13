<?php

declare(strict_types=1);

namespace Nesto\Cli;

use Nesto\Core\NestoApplication;
use Nesto\Core\NestoFactory;

/**
 * Where the CLI is running: the project root, the contents of nesto.config.php and a lazily booted app.
 *
 *   $ctx = ProjectContext::discover(getcwd());          // walks up until a composer.json is found
 *   $ctx->rootDir();                                     // '/path/to/project'
 *   $ctx->config('sourceRoot');                          // 'src'
 *   $ctx->app();                                         // NestoFactory::createApplicationContext(rootModule), cached
 *   $ctx->closeApp();                                    // app.close() (shutdown hooks) once the command is done
 */
final class ProjectContext
{
    public const DEFAULTS = [
        'rootModule' => 'App\\AppModule',
        'sourceRoot' => 'src',
        'appNamespace' => 'App',
        'bootstrap' => 'public/index.php',
        'entities' => 'src/**/Entities',
        'migrations' => 'database/migrations',
        'seeders' => 'database/seeders',
        'tests' => 'tests',
        'testNamespace' => 'Tests',
        'commands' => [],
    ];

    private ?NestoApplication $app = null;
    /** True when app() booted the application (so closeApp() owns its shutdown); false for setApp(). */
    private bool $ownsApp = false;
    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly string $rootDir, array $config = [])
    {
        $this->config = $config + self::DEFAULTS;
    }

    /** Find the nearest directory containing composer.json, starting at $cwd. Returns null if none. */
    public static function discover(string $cwd): ?self
    {
        $dir = rtrim($cwd, DIRECTORY_SEPARATOR);
        while ($dir !== '' && $dir !== dirname($dir)) {
            if (is_file($dir . '/composer.json')) {
                return self::fromDir($dir);
            }
            $dir = dirname($dir);
        }
        if ($dir !== '' && is_file($dir . '/composer.json')) {
            return self::fromDir($dir);
        }

        return null;
    }

    /** Build a context for $dir, loading nesto.config.php when it exists. */
    public static function fromDir(string $dir): self
    {
        $config = [];
        $file = $dir . '/nesto.config.php';
        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                $config = $loaded;
            }
        }

        return new self($dir, $config);
    }

    public function rootDir(): string
    {
        return $this->rootDir;
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function allConfig(): array
    {
        return $this->config;
    }

    public function hasConfigFile(): bool
    {
        return is_file($this->rootDir . '/nesto.config.php');
    }

    /** Absolute path for a project-relative path. */
    public function path(string $relative = ''): string
    {
        return rtrim($this->rootDir . '/' . ltrim($relative, '/'), '/');
    }

    public function sourceRoot(): string
    {
        return $this->path((string) $this->config('sourceRoot'));
    }

    public function appNamespace(): string
    {
        return trim((string) $this->config('appNamespace'), '\\');
    }

    public function rootModule(): string
    {
        return (string) $this->config('rootModule');
    }

    /** The file declaring the root module: derived from sourceRoot/appNamespace, else found by reflection. */
    public function rootModuleFile(): ?string
    {
        $rootClass = ltrim($this->rootModule(), '\\');
        if ($rootClass === '') {
            return null;
        }
        $appNs = $this->appNamespace();
        $relative = $rootClass;
        if ($appNs !== '' && str_starts_with($rootClass, $appNs . '\\')) {
            $relative = substr($rootClass, strlen($appNs) + 1);
        }
        $file = $this->sourceRoot() . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            return $file;
        }
        if (class_exists($rootClass)) {
            $path = (new \ReflectionClass($rootClass))->getFileName();

            return $path !== false ? $path : null;
        }

        return null;
    }

    /** Path relative to the project root, for console output. */
    public function relative(string $file): string
    {
        $root = rtrim($this->rootDir, '/') . '/';

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }

    public function hasRootModule(): bool
    {
        return class_exists($this->rootModule());
    }

    /** Boot (once) the application context from the configured root module. */
    public function app(array $options = []): NestoApplication
    {
        if ($this->app !== null) {
            return $this->app;
        }
        $root = $this->rootModule();
        if (! class_exists($root)) {
            throw new CliException(sprintf(
                'Root module "%s" was not found. Check "rootModule" in nesto.config.php and run "composer dump-autoload".',
                $root
            ));
        }

        $this->ownsApp = true;

        return $this->app = NestoFactory::createApplicationContext($root, $options);
    }

    public function setApp(NestoApplication $app): void
    {
        $this->app = $app;
        $this->ownsApp = false;
    }

    /**
     * Close the application app() booted (OnModuleDestroy, BeforeApplicationShutdown, OnApplicationShutdown)
     * and forget it, so the next app() boots a fresh one. Apps given through setApp() are left to their owner.
     */
    public function closeApp(?string $signal = null): void
    {
        if ($this->app === null || ! $this->ownsApp) {
            return;
        }
        $app = $this->app;
        $this->app = null;
        $this->ownsApp = false;
        $app->close($signal);
    }

    public function hasApp(): bool
    {
        return $this->app !== null;
    }
}
