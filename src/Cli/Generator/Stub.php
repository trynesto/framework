<?php

declare(strict_types=1);

namespace Nesto\Cli\Generator;

/**
 * Loads a *.stub file from src/Cli/stubs and replaces `{{ placeholder }}` tokens.
 *
 *   Stub::render('controller', ['namespace' => 'App\Cats', 'class' => 'CatsController', 'route' => 'cats']);
 */
final class Stub
{
    public static function dir(): string
    {
        return dirname(__DIR__) . '/stubs';
    }

    public static function path(string $name): string
    {
        return self::dir() . '/' . $name . '.stub';
    }

    public static function exists(string $name): bool
    {
        return is_file(self::path($name));
    }

    /** @return list<string> all stub names available */
    public static function all(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.stub') ?: [] as $file) {
            $out[] = basename($file, '.stub');
        }
        sort($out);

        return $out;
    }

    /** @param array<string, scalar|null> $vars */
    public static function render(string $name, array $vars): string
    {
        $file = self::path($name);
        if (! is_file($file)) {
            throw new \RuntimeException("Stub \"$name\" not found at $file.");
        }

        return self::renderString((string) file_get_contents($file), $vars);
    }

    /** @param array<string, scalar|null> $vars */
    public static function renderString(string $template, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', function (array $m) use ($vars, $template) {
            if (! array_key_exists($m[1], $vars)) {
                throw new \RuntimeException("Stub placeholder \"{$m[1]}\" has no value.");
            }

            return (string) $vars[$m[1]];
        }, $template) ?? $template;
    }
}
