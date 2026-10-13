<?php

declare(strict_types=1);

namespace Nesto\Common\Debug;

/**
 * Renders any PHP value for `console()` (Laravel's `dd()`): as text for terminals (ANSI colors optional) or as
 * collapsible HTML for browsers. Objects show every property with its visibility (`+` public, `#` protected,
 * `-` private), uninitialized typed properties and recursion are marked, and nesting stops at MAX_DEPTH.
 */
final class Dumper
{
    public const MAX_DEPTH = 12;

    /** Requests the framework is handling right now (console() then throws instead of exiting the process). */
    private static int $handling = 0;

    public static function enterRequest(): void
    {
        self::$handling++;
    }

    public static function leaveRequest(): void
    {
        self::$handling = max(0, self::$handling - 1);
    }

    public static function handlingRequest(): bool
    {
        return self::$handling > 0;
    }

    public static function text(mixed $value, bool $colors = false): string
    {
        $paint = $colors
            ? static fn (string $text, string $kind) => "\033[" . match ($kind) {
                'string' => '32', 'number' => '34', 'const' => '35', 'class' => '33', 'key' => '36', default => '90',
            } . 'm' . $text . "\033[0m"
            : static fn (string $text, string $kind) => $text;

        return self::render($value, $paint, '  ', "\n", 0, []);
    }

    public static function html(mixed $value): string
    {
        $paint = static fn (string $text, string $kind) => '<span class="mdump-' . $kind . '">' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE) . '</span>';

        return '<pre class="mdump">' . self::render($value, $paint, '  ', "\n", 0, [], html: true) . '</pre>';
    }

    /**
     * @param callable(string, string): string $paint
     * @param array<int, true>                 $seen  ids of the objects on the current path (recursion guard)
     */
    private static function render(mixed $value, callable $paint, string $indent, string $nl, int $depth, array $seen, bool $html = false): string
    {
        return match (true) {
            $value === null => $paint('null', 'const'),
            is_bool($value) => $paint($value ? 'true' : 'false', 'const'),
            is_int($value), is_float($value) => $paint(var_export($value, true), 'number'),
            is_string($value) => $paint('"' . $value . '"', 'string') . (strlen($value) > 0 ? $paint(' (' . strlen($value) . ')', 'note') : ''),
            is_array($value) => self::renderArray($value, $paint, $indent, $nl, $depth, $seen, $html),
            $value instanceof \UnitEnum => $paint($value::class . '::' . $value->name, 'class')
                . ($value instanceof \BackedEnum ? $paint(' = ' . var_export($value->value, true), 'note') : ''),
            $value instanceof \DateTimeInterface => $paint($value::class, 'class') . ' ' . $paint($value->format('Y-m-d H:i:s.u P'), 'string'),
            $value instanceof \Closure => $paint('Closure', 'class') . self::closureLocation($value, $paint),
            is_object($value) => self::renderObject($value, $paint, $indent, $nl, $depth, $seen, $html),
            is_resource($value) => $paint('resource(' . get_resource_type($value) . ')', 'class'),
            default => $paint(get_debug_type($value), 'note'),
        };
    }

    /** @param array<mixed> $value */
    private static function renderArray(array $value, callable $paint, string $indent, string $nl, int $depth, array $seen, bool $html): string
    {
        $head = $paint('array:' . count($value), 'note');
        if ($value === []) {
            return $head . ' []';
        }
        if ($depth >= self::MAX_DEPTH) {
            return $head . ' [ … ]';
        }
        $list = array_is_list($value);
        $lines = [];
        foreach ($value as $key => $item) {
            $label = $list ? $paint((string) $key, 'note') : $paint(is_string($key) ? '"' . $key . '"' : (string) $key, 'key');
            $lines[] = $label . ' => ' . self::render($item, $paint, $indent, $nl, $depth + 1, $seen, $html);
        }

        return self::block($head . ' [', $lines, ']', $indent, $nl, $depth, $html);
    }

    private static function renderObject(object $value, callable $paint, string $indent, string $nl, int $depth, array $seen, bool $html): string
    {
        $id = spl_object_id($value);
        $head = $paint($value::class, 'class') . ' ' . $paint('#' . $id, 'note');
        if (isset($seen[$id])) {
            return $head . ' ' . $paint('{ *RECURSION* }', 'note');
        }
        if ($depth >= self::MAX_DEPTH) {
            return $head . ' { … }';
        }
        $seen[$id] = true;
        $lines = [];
        $class = new \ReflectionObject($value);
        $shown = [];
        foreach (self::properties($class) as $prop) {
            if ($prop->isStatic() || isset($shown[$prop->getName()])) {
                continue;
            }
            $shown[$prop->getName()] = true;
            $marker = $prop->isPublic() ? '+' : ($prop->isProtected() ? '#' : '-');
            $label = $paint($marker, 'note') . $paint($prop->getName(), 'key');
            $lines[] = $label . ': ' . ($prop->isInitialized($value)
                ? self::render($prop->getValue($value), $paint, $indent, $nl, $depth + 1, $seen, $html)
                : $paint('uninitialized', 'note'));
        }
        foreach (get_object_vars($value) as $name => $item) { // dynamic properties (stdClass, …)
            if (! isset($shown[$name])) {
                $lines[] = $paint('+', 'note') . $paint((string) $name, 'key') . ': ' . self::render($item, $paint, $indent, $nl, $depth + 1, $seen, $html);
            }
        }
        if ($lines === []) {
            return $head . ' {}';
        }

        return self::block($head . ' {', $lines, '}', $indent, $nl, $depth, $html);
    }

    /** @return list<\ReflectionProperty> the class's properties, then the private ones of its parents */
    private static function properties(\ReflectionClass $class): array
    {
        $props = $class->getProperties();
        for ($parent = $class->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            foreach ($parent->getProperties(\ReflectionProperty::IS_PRIVATE) as $p) {
                $props[] = $p;
            }
        }

        return $props;
    }

    /** @param list<string> $lines */
    private static function block(string $open, array $lines, string $close, string $indent, string $nl, int $depth, bool $html): string
    {
        $pad = str_repeat($indent, $depth + 1);
        $body = $nl . $pad . implode($nl . $pad, $lines) . $nl . str_repeat($indent, $depth);
        if (! $html) {
            return $open . $body . $close;
        }
        // click the opening line to fold a level; levels below depth 2 start folded, like Symfony's dumper
        return '<span class="mdump-toggle" onclick="this.nextElementSibling.classList.toggle(\'mdump-folded\')">' . $open . '</span>'
            . '<span class="mdump-body' . ($depth < 2 ? '' : ' mdump-folded') . '">' . $body . '</span>' . $close;
    }

    private static function closureLocation(\Closure $closure, callable $paint): string
    {
        $ref = new \ReflectionFunction($closure);
        $file = $ref->getFileName();

        return $file === false ? '' : ' ' . $paint('{' . basename($file) . ':' . $ref->getStartLine() . '}', 'note');
    }
}
