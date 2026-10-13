<?php

declare(strict_types=1);

namespace Nesto\Core\Routing;

use ReflectionClass;
use ReflectionMethod;

/** One compiled route: HTTP method + path pattern → controller::handler. */
final class Route
{
    /** @var list<string> param names in order */
    public readonly array $paramNames;
    public readonly string $regex;
    public readonly bool $isStatic;
    /** @var list<string> compiled #[Controller(host: …)] patterns; empty = any host */
    public readonly array $hostRegexes;
    private ?ReflectionClass $classRef = null;
    private ?ReflectionMethod $methodRef = null;

    /**
     * @param class-string $controller
     * @param array<string, mixed> $meta extra info (httpCode, headers, redirect, version, host...)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $controller,
        public readonly string $handler,
        public readonly array $meta = [],
        public readonly ?string $moduleClass = null,
    ) {
        [$this->regex, $this->paramNames, $this->isStatic] = self::compile($path);
        $this->hostRegexes = array_map(self::compileHost(...), array_values((array) ($meta['host'] ?? [])));
    }

    /** 'admin.example.com' or ':account.example.com' (Nest's host param) → regex on the lower-cased host. */
    public static function compileHost(string $host): string
    {
        $parts = array_map(
            static fn (string $label) => preg_match('/^:([a-zA-Z_][a-zA-Z0-9_]*)$/', $label, $m) ? '(?P<' . $m[1] . '>[^.]+)' : preg_quote(strtolower($label), '#'),
            explode('.', $host),
        );

        return '#^' . implode('\\.', $parts) . '$#';
    }

    /**
     * Host params when the request host satisfies the controller's host (empty array without a host
     * restriction), null when it doesn't.
     *
     * @return array<string, string>|null
     */
    public function matchHost(?string $host): ?array
    {
        if ($this->hostRegexes === []) {
            return [];
        }
        $host = strtolower((string) preg_replace('/:\d+$/', '', (string) $host));
        foreach ($this->hostRegexes as $regex) {
            if (preg_match($regex, $host, $m)) {
                return array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            }
        }

        return null;
    }

    /** @return array{0: string, 1: list<string>, 2: bool} */
    public static function compile(string $path): array
    {
        $names = [];
        $isStatic = ! str_contains($path, '{') && ! str_contains($path, '*') && ! str_contains($path, ':');
        // support {id}, {id:\d+}, :id (Nest style) and * wildcard
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}|:([a-zA-Z_][a-zA-Z0-9_]*)|(\*)#', function ($m) use (&$names) {
            if (($m[4] ?? '') === '*') {
                return '.*';
            }
            $name = $m[1] !== '' ? $m[1] : $m[3];
            $names[] = $name;
            $pattern = ($m[2] ?? '') !== '' ? $m[2] : '[^/]+';

            return '(?P<' . $name . '>' . $pattern . ')';
        }, $path);

        return ['#^' . $regex . '$#u', $names, $isStatic];
    }

    public function classReflection(): ReflectionClass
    {
        return $this->classRef ??= new ReflectionClass($this->controller);
    }

    public function methodReflection(): ReflectionMethod
    {
        return $this->methodRef ??= new ReflectionMethod($this->controller, $this->handler);
    }

    public function id(): string
    {
        return $this->method . ' ' . $this->path;
    }
}
