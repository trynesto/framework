<?php

declare(strict_types=1);

use Nesto\Common\Provider;

if (! defined('VERSION_NEUTRAL')) {
    /** Nest's VERSION_NEUTRAL: `#[Version(VERSION_NEUTRAL)]` serves a route without a version segment. */
    define('VERSION_NEUTRAL', \Nesto\Common\Attributes\Version::NEUTRAL);
}

if (! function_exists('data_get')) {
    /** Dot-notation reader for nested arrays / objects. */
    function data_get(mixed $target, string|int|null $key, mixed $default = null): mixed
    {
        if ($key === null || $key === '') {
            return $target;
        }
        foreach (explode('.', (string) $key) as $segment) {
            if (is_array($target) && array_key_exists($segment, $target)) {
                $target = $target[$segment];
            } elseif (is_object($target) && isset($target->{$segment})) {
                $target = $target->{$segment};
            } else {
                return $default;
            }
        }

        return $target;
    }
}

if (! function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

if (! function_exists('forwardRef')) {
    /** Lazily reference a class to break circular imports / injections. */
    function forwardRef(Closure $fn): \Nesto\Common\ForwardRef
    {
        return new \Nesto\Common\ForwardRef($fn);
    }
}

if (! function_exists('provide')) {
    function provide(string $token): Provider
    {
        return new Provider($token);
    }
}

if (! function_exists('base_path')) {
    /** The project root (the folder with nesto.config.php / composer.json). */
    function base_path(string $path = ''): string
    {
        return \Nesto\Core\Paths::base($path);
    }
}

if (! function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return \Nesto\Core\Paths::storage($path);
    }
}

if (! function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        return \Nesto\Core\Paths::public($path);
    }
}

if (! function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        return \Nesto\Core\Paths::config($path);
    }
}

if (! function_exists('console')) {
    /**
     * Dump the values and stop, like Laravel's `dd()`. In a terminal (commands, scripts) it prints and exits with
     * code 1; during an HTTP request the framework answers with the dump (HTTP 500: HTML for browsers, plain text
     * for API clients) and the process keeps running, so a FrankenPHP worker is not killed.
     */
    function console(mixed ...$values): never
    {
        $frame = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        $location = isset($frame['file']) ? $frame['file'] . ':' . ($frame['line'] ?? 0) : 'unknown';

        if (\Nesto\Common\Debug\Dumper::handlingRequest()) {
            throw new \Nesto\Common\Debug\DumpAndDie($values, $location);
        }
        if (PHP_SAPI === 'cli') {
            $colors = function_exists('stream_isatty') && @stream_isatty(STDOUT);
            fwrite(STDOUT, \Nesto\Common\Debug\DumpAndDie::text($values, $location, $colors));
            exit(1);
        }
        if (! headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo \Nesto\Common\Debug\DumpAndDie::page($values, $location);
        exit(1);
    }
}
