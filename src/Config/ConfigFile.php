<?php

declare(strict_types=1);

namespace Nesto\Config;

use Nesto\Core\Paths;

/**
 * Reads one project config file (config/<key>.php) on top of built-in defaults, before the container exists.
 * The modules that pick a driver from .env (LoggerModule, DatabaseModule, CacheModule) use it while the module
 * graph is scanned:
 *
 *   ConfigFile::load('cache', ['default' => 'memory']);   // defaults < config/cache.php
 *
 * The project's .env is loaded first (never overriding real environment variables), so `env()` inside the
 * file sees it even when ConfigModule was not imported before the module asking.
 */
final class ConfigFile
{
    /**
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    public static function load(string $key, array $defaults = []): array
    {
        self::loadEnv();
        $file = Paths::config($key . '.php');
        if (! is_file($file)) {
            return $defaults;
        }
        $values = (static fn (string $__file) => require $__file)($file);
        if (! is_array($values)) {
            throw new ConfigException("Config file $file must return an array.");
        }

        return ConfigModule::merge($defaults, $values);
    }

    /** Is there a config/<key>.php in the project? */
    public static function exists(string $key): bool
    {
        return is_file(Paths::config($key . '.php'));
    }

    /** Load the project's .env into the environment (existing variables win, missing file ignored). */
    public static function loadEnv(): void
    {
        Env::load(Paths::base('.env'));
    }

    /** APP_ENV is local / development / testing, or not set at all. */
    public static function isLocal(): bool
    {
        $env = strtolower((string) (env('APP_ENV') ?? 'local'));

        return in_array($env, ['', 'local', 'dev', 'develop', 'development', 'testing', 'test'], true);
    }

    /** A boolean from env-style values: true/false, 1/0, yes/no, on/off. */
    public static function bool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /** A list from "a,b , c" (or an array). @return list<string> */
    public static function list(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $value), fn (string $v) => $v !== ''));
        }

        return self::list(explode(',', (string) $value));
    }
}
