<?php

declare(strict_types=1);

namespace Nesto\Core;

use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;

/**
 * Where the application lives: base_path(), storage_path(), public_path(), config_path().
 *
 * The base path is found once: set explicitly (CLI, tests), else detected by NestoFactory from the root
 * module's file (the nearest folder with nesto.config.php or composer.json), else the folder above vendor/.
 * A framework root module (Test::createTestingModule()'s) points at the app through its imports instead.
 */
final class Paths
{
    private static ?string $base = null;
    private static bool $explicit = false;

    /** Pin the base path (the CLI does this with the project root); null forgets it. */
    public static function setBase(?string $path): void
    {
        self::$base = $path === null ? null : rtrim($path, '/');
        self::$explicit = $path !== null;
    }

    /** Detect the base path from a file inside the project, unless it was pinned with setBase(). */
    public static function detectFrom(string $file): void
    {
        if (self::$explicit) {
            return;
        }
        for ($dir = is_dir($file) ? $file : dirname($file); $dir !== dirname($dir); $dir = dirname($dir)) {
            if (is_file("$dir/nesto.config.php") || is_file("$dir/composer.json")) {
                self::$base = $dir;

                return;
            }
        }
    }

    /** detectFrom() the root module's file; for a framework module, the first app class it imports/declares. */
    public static function detectFromModule(string|DynamicModule $root): void
    {
        $class = $root instanceof DynamicModule ? $root->module : $root;
        $file = self::fileOf($class);
        if ($file !== null && self::isFrameworkFile($file) && $root instanceof DynamicModule) {
            foreach ([...$root->imports, ...$root->controllers, ...$root->providers] as $entry) {
                $candidate = self::fileOf(match (true) {
                    is_string($entry) => $entry,
                    is_array($entry) => $entry[0] ?? null, // [Module::class, 'forFeature', [...]]
                    $entry instanceof DynamicModule => $entry->module,
                    $entry instanceof Provider => $entry->useClass,
                    is_object($entry) => $entry::class, // DynamicModuleFactory
                    default => null,
                });
                if ($candidate !== null && ! self::isFrameworkFile($candidate)) {
                    $file = $candidate;
                    break;
                }
            }
        }
        if ($file !== null) {
            self::detectFrom($file);
        }
    }

    public static function base(string $path = ''): string
    {
        self::$base ??= self::fallback();

        return self::join(self::$base, $path);
    }

    public static function storage(string $path = ''): string
    {
        return self::join(self::base('storage'), $path);
    }

    public static function public(string $path = ''): string
    {
        return self::join(self::base('public'), $path);
    }

    public static function config(string $path = ''): string
    {
        return self::join(self::base('config'), $path);
    }

    /** Absolute paths are kept, relative ones are resolved from the base path. */
    public static function resolve(string $path): string
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) ? $path : self::base($path);
    }

    private static function fileOf(mixed $class): ?string
    {
        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }
        $file = (new \ReflectionClass($class))->getFileName();

        return $file === false ? null : $file;
    }

    private static function isFrameworkFile(string $file): bool
    {
        return str_starts_with($file, dirname(__DIR__) . DIRECTORY_SEPARATOR);
    }

    private static function fallback(): string
    {
        $vendor = \Nesto\Core\Packages\PackageManifest::vendorDir();

        return $vendor !== null ? dirname($vendor) : rtrim((string) getcwd(), '/');
    }

    private static function join(string $base, string $path): string
    {
        $path = ltrim($path, '/');

        return $path === '' ? $base : "$base/$path";
    }
}
