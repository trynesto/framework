<?php

declare(strict_types=1);

namespace Nesto\Database;

/**
 * Finds the entity classes behind a glob such as nesto.config.php's `'entities' => 'src/**\/Entities'`:
 * every PHP file under a matching directory (or matching the pattern itself) declaring a class with
 * #[Entity], #[ViewEntity] or #[ChildEntity]. Class names come from the files' namespace + class
 * declarations, so any autoloader layout works.
 *
 *   EntityScanner::scan('src/**\/Entities', '/app');           // ['App\Cats\Entities\Cat', ...]
 *   EntityScanner::scan(['src/**\/*.entity.php', App\Legacy\User::class], '/app');
 *
 * `**` matches any number of folders, `*` and `?` stay within one. Results are cached per process (workers).
 */
final class EntityScanner
{
    private const ATTRIBUTES = [
        'Nesto\\Orm\\Attributes\\Entity',
        'Nesto\\Orm\\Attributes\\ViewEntity',
        'Nesto\\Orm\\Attributes\\ChildEntity',
    ];

    /** @var array<string, list<class-string>> */
    private static array $cache = [];

    /**
     * @param string|list<string> $patterns globs relative to $baseDir (or absolute), or class names
     * @return list<class-string>
     */
    public static function scan(string|array $patterns, string $baseDir): array
    {
        $patterns = array_values(array_filter((array) $patterns, fn ($p) => is_string($p) && trim($p) !== ''));
        $key = $baseDir . "\0" . implode("\0", $patterns);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $classes = [];
        foreach ($patterns as $pattern) {
            if (strpbrk($pattern, '/*?') === false && class_exists($pattern)) {
                $classes[] = $pattern;
                continue;
            }
            foreach (self::files($pattern, $baseDir) as $file) {
                foreach (self::classesIn($file) as $class) {
                    if (self::isEntity($class)) {
                        $classes[] = $class;
                    }
                }
            }
        }

        return self::$cache[$key] = array_values(array_unique($classes));
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /** @return list<string> PHP files matched by the pattern (directories match everything below them) */
    public static function files(string $pattern, string $baseDir): array
    {
        $pattern = str_replace('\\', '/', trim($pattern));
        $absolute = str_starts_with($pattern, '/') || preg_match('#^[A-Za-z]:/#', $pattern) === 1;
        $full = $absolute ? $pattern : rtrim(str_replace('\\', '/', $baseDir), '/') . '/' . (str_starts_with($pattern, './') ? substr($pattern, 2) : $pattern);
        $full = rtrim($full, '/');

        // the static part before the first wildcard is where the walk starts
        $segments = explode('/', $full);
        $root = [];
        foreach ($segments as $segment) {
            if (strpbrk($segment, '*?[') !== false) {
                break;
            }
            $root[] = $segment;
        }
        $rootDir = implode('/', $root);
        if ($rootDir === $full) {
            if (is_file($full)) {
                return [$full];
            }
            $rootDir = $full; // a plain directory: everything below it
            $regex = '#^' . preg_quote($full, '#') . '$#';
        } else {
            $regex = self::regex($full);
        }
        if (! is_dir($rootDir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($rootDir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS));
        foreach ($iterator as $info) {
            /** @var \SplFileInfo $info */
            if (! $info->isFile() || $info->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $info->getPathname());
            if (preg_match($regex, $path) === 1) {
                $files[] = $path;
                continue;
            }
            // the file sits (somewhere) under a matching directory
            for ($dir = dirname($path); strlen($dir) >= strlen($rootDir) && $dir !== dirname($dir); $dir = dirname($dir)) {
                if (preg_match($regex, $dir) === 1) {
                    $files[] = $path;
                    break;
                }
            }
        }
        sort($files);

        return $files;
    }

    private static function regex(string $glob): string
    {
        $out = '';
        $length = strlen($glob);
        for ($i = 0; $i < $length; $i++) {
            $char = $glob[$i];
            if ($char === '*' && ($glob[$i + 1] ?? '') === '*') {
                $i++;
                if (($glob[$i + 1] ?? '') === '/') {
                    $i++;
                    $out .= '(?:.*/)?';
                } else {
                    $out .= '.*';
                }
            } elseif ($char === '*') {
                $out .= '[^/]*';
            } elseif ($char === '?') {
                $out .= '[^/]';
            } else {
                $out .= preg_quote($char, '#');
            }
        }

        return '#^' . $out . '$#';
    }

    /** @return list<string> fully qualified classes declared in a file */
    public static function classesIn(string $file): array
    {
        $code = @file_get_contents($file);
        if ($code === false || ! str_contains($code, 'class')) {
            return [];
        }
        $tokens = \PhpToken::tokenize($code);
        $namespace = '';
        $classes = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token->is(T_NAMESPACE)) {
                $namespace = '';
                for ($j = $i + 1; $j < $count && ! $tokens[$j]->is([';', '{']); $j++) {
                    if ($tokens[$j]->is([T_NAME_QUALIFIED, T_STRING])) {
                        $namespace .= $tokens[$j]->text;
                    }
                }
                $i = $j;
            } elseif ($token->is(T_CLASS)) {
                $prev = $i - 1;
                while ($prev >= 0 && $tokens[$prev]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                    $prev--;
                }
                if ($prev >= 0 && ($tokens[$prev]->is(T_DOUBLE_COLON) || $tokens[$prev]->is(T_NEW))) {
                    continue; // Foo::class / new class
                }
                for ($j = $i + 1; $j < $count && $tokens[$j]->is(T_WHITESPACE); $j++);
                if ($j < $count && $tokens[$j]->is(T_STRING)) {
                    $classes[] = ltrim($namespace . '\\' . $tokens[$j]->text, '\\');
                }
            }
        }

        return $classes;
    }

    private static function isEntity(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }
        $reflection = new \ReflectionClass($class);
        if ($reflection->isAbstract()) {
            return false;
        }
        foreach (self::ATTRIBUTES as $attribute) {
            if ($reflection->getAttributes($attribute) !== []) {
                return true;
            }
        }

        return false;
    }
}
