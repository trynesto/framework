<?php

declare(strict_types=1);

namespace Nesto\Cli;

use Nesto\Cli\Commands\BuiltIn;
use Nesto\Core\Packages\PackageManifest;

/**
 * Builds the console application: built-in commands plus the project's own (nesto.config.php "commands").
 *
 * A project's `nesto` file (Nesto's `artisan`) is just:
 *
 *   require __DIR__ . '/vendor/autoload.php';
 *   exit(Nesto\Cli\Console::run(__DIR__, $argv));
 *
 * so every command runs as `php nesto <command>`.
 */
final class Console
{
    /** @param list<string> $argv the script's $argv (script name first) */
    public static function run(string $projectRoot, array $argv): int
    {
        $name = 'php ' . basename($argv[0] ?? 'nesto');

        return self::application(ProjectContext::fromDir($projectRoot), $name)->run(array_slice($argv, 1));
    }

    public static function application(?ProjectContext $context, string $name = 'php nesto'): Application
    {
        if ($context !== null) {
            \Nesto\Core\Paths::setBase($context->rootDir());
        }
        $app = new Application($name, Application::frameworkVersion());
        $app->setContext($context);
        $app->add(...BuiltIn::commands());

        // commands from nesto.config.php, then the ones installed packages declare (extra.nesto.commands)
        $classes = (array) ($context?->config('commands') ?? []);
        if ($context !== null && is_dir($context->path('vendor'))) {
            $classes = [...$classes, ...PackageManifest::forProject($context->path('vendor'))->commands()];
        }

        foreach ($classes as $commandClass) {
            if (! is_string($commandClass) || ! class_exists($commandClass)) {
                fwrite(STDERR, 'Warning: command class "' . (is_string($commandClass) ? $commandClass : gettype($commandClass)) . "\" (nesto.config.php or an installed package) was not found.\n");
                continue;
            }
            $command = new $commandClass();
            if (! $command instanceof Command) {
                fwrite(STDERR, "Warning: $commandClass does not extend Nesto\\Cli\\Command.\n");
                continue;
            }
            $app->add($command);
        }

        return $app;
    }
}
