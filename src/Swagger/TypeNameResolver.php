<?php

declare(strict_types=1);

namespace Nesto\Swagger;

/**
 * Resolves class names written in docblocks (`@var list<Tag>`) the way PHP resolves them in code: through the
 * declaring file's `use` imports (aliases and group uses included), then its namespace, then the global namespace.
 * The class file is tokenized once per class.
 *
 *   TypeNameResolver::resolve('Tag', Post::class);   // 'App\Tags\Entities\Tag' with `use App\Tags\Entities\Tag;`
 *
 * @internal
 */
final class TypeNameResolver
{
    /** @var array<string, array{namespace: string, uses: array<string, string>}> class => lower-case alias => FQCN */
    private static array $cache = [];

    /**
     * @param string|null $context the declaring class (its file's imports are used) or just a namespace
     * @return string the class name if it exists, else $name unchanged
     */
    public static function resolve(string $name, ?string $context): string
    {
        $name = trim($name);
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $scope = self::scope($context);
        $first = strtolower(explode('\\', $name, 2)[0]);
        $candidates = [];
        if (isset($scope['uses'][$first])) {
            $rest = str_contains($name, '\\') ? substr($name, strpos($name, '\\')) : '';
            $candidates[] = $scope['uses'][$first] . $rest;
        }
        if ($scope['namespace'] !== '') {
            $candidates[] = $scope['namespace'] . '\\' . $name;
        }
        $candidates[] = $name;
        foreach ($candidates as $class) {
            if (class_exists($class) || interface_exists($class) || enum_exists($class)) {
                return $class;
            }
        }

        return $name;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /** @return array{namespace: string, uses: array<string, string>} */
    private static function scope(?string $context): array
    {
        if ($context === null || $context === '') {
            return ['namespace' => '', 'uses' => []];
        }
        if (! class_exists($context) && ! interface_exists($context) && ! trait_exists($context) && ! enum_exists($context)) {
            return ['namespace' => trim($context, '\\'), 'uses' => []]; // a namespace
        }
        if (isset(self::$cache[$context])) {
            return self::$cache[$context];
        }
        $class = new \ReflectionClass($context);
        $file = $class->getFileName();
        $uses = $file !== false && is_file($file) ? self::parse((string) file_get_contents($file), $class->getNamespaceName(), $class->getShortName()) : [];

        return self::$cache[$context] = ['namespace' => $class->getNamespaceName(), 'uses' => $uses];
    }

    /**
     * The class imports in effect where `$short` is declared (in namespace `$namespace`).
     *
     * @return array<string, string> lower-case alias => FQCN
     */
    private static function parse(string $code, string $namespace, string $short): array
    {
        $tokens = token_get_all($code);
        $count = count($tokens);
        $currentNs = '';
        $uses = [];
        $depth = 0;
        $nsDepth = 0;
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                continue;
            }
            if ($token === '}') {
                $depth--;
                continue;
            }
            if (! is_array($token)) {
                continue;
            }
            if ($token[0] === T_NAMESPACE) {
                [$name, $i, $end] = self::readUntil($tokens, $i + 1, [';', '{']);
                $currentNs = trim($name, '\\');
                $uses = [];
                $nsDepth = $end === '{' ? $depth + 1 : $depth;
                if ($end === '{') {
                    $depth++;
                }
                continue;
            }
            if ($token[0] === T_USE && $depth === $nsDepth) {
                [$statement, $i] = self::readUntil($tokens, $i + 1, [';']);
                $uses = array_replace($uses, self::imports($statement));
                continue;
            }
            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $currentNs === $namespace) {
                $next = $i + 1;
                while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                    $next++;
                }
                if (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING && $tokens[$next][1] === $short) {
                    return $uses;
                }
            }
        }

        return $uses;
    }

    /**
     * Concatenates the tokens from $start up to one of the $ends (whitespace collapsed, comments dropped).
     *
     * @param list<mixed> $tokens
     * @param list<string> $ends
     * @return array{0: string, 1: int, 2: string} [text, index of the end token, end token]
     */
    private static function readUntil(array $tokens, int $start, array $ends): array
    {
        $text = '';
        for ($i = $start, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_string($token) && in_array($token, $ends, true)) {
                return [trim($text), $i, $token];
            }
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $text .= $token[0] === T_WHITESPACE ? ' ' : $token[1];
            } else {
                $text .= $token;
            }
        }

        return [trim($text), $count, ''];
    }

    /**
     * `A\B`, `A\B as C, D\E`, `A\{B, C as D}` → alias => class; `use function` / `use const` are ignored.
     *
     * @return array<string, string>
     */
    private static function imports(string $statement): array
    {
        if (preg_match('/^(function|const)\s/i', $statement)) {
            return [];
        }
        $items = [];
        if (preg_match('/^(.*?)\\\\?\s*\{(.*)\}$/s', $statement, $group)) {
            $prefix = trim($group[1], " \\");
            foreach (explode(',', $group[2]) as $item) {
                if (trim($item) !== '' && ! preg_match('/^\s*(function|const)\s/i', $item)) {
                    $items[] = $prefix . '\\' . trim($item);
                }
            }
        } else {
            $items = explode(',', $statement);
        }
        $out = [];
        foreach ($items as $item) {
            if (preg_match('/^\s*\\\\?([\w\\\\]+)(?:\s+as\s+(\w+))?\s*$/i', $item, $m)) {
                $class = $m[1];
                $alias = $m[2] ?? (str_contains($class, '\\') ? substr($class, strrpos($class, '\\') + 1) : $class);
                $out[strtolower($alias)] = $class;
            }
        }

        return $out;
    }
}
