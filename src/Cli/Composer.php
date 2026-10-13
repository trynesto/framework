<?php

declare(strict_types=1);

namespace Nesto\Cli;

use Composer\Autoload\ClassLoader;

/** Locates and runs Composer for the CLI (`nesto new`, `php nesto add`). */
final class Composer
{
    /** Shell-escaped composer command, or null when it is not on PATH. */
    public static function binary(): ?string
    {
        foreach (['composer', 'composer.phar'] as $bin) {
            $path = trim((string) shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
            if ($path !== '') {
                return escapeshellarg($path); // the path may contain spaces (e.g. "Application Support")
            }
        }

        return null;
    }

    /** Run `composer <args>` inside $dir with live output; returns the exit code. */
    public static function run(string $dir, string ...$args): int
    {
        $composer = self::binary() ?? throw new CliException('composer was not found on PATH.');
        passthru(sprintf('cd %s && %s %s 2>&1', escapeshellarg($dir), $composer, implode(' ', array_map('escapeshellarg', $args))), $code);

        return $code;
    }

    /**
     * Make classes installed by a `composer require` that ran in a child process loadable in this one: the
     * autoloader registered at startup still holds the old maps, so a second loader is built from the fresh
     * vendor/composer/autoload_*.php files (appended, classes loaded so far are unaffected).
     */
    public static function refreshAutoloader(string $vendorDir): bool
    {
        $dir = rtrim($vendorDir, '/') . '/composer';
        if (! class_exists(ClassLoader::class) || ! is_dir($dir)) {
            return false;
        }
        $maps = static fn (string $file): array => is_file("$dir/$file") ? (array) require "$dir/$file" : [];
        $loader = new ClassLoader($vendorDir);
        foreach ($maps('autoload_namespaces.php') as $prefix => $paths) {
            $loader->set($prefix, $paths);
        }
        foreach ($maps('autoload_psr4.php') as $prefix => $paths) {
            $loader->setPsr4($prefix, $paths);
        }
        $loader->addClassMap($maps('autoload_classmap.php'));
        $loader->register();

        return true;
    }
}
