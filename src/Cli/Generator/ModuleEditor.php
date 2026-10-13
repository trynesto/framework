<?php

declare(strict_types=1);

namespace Nesto\Cli\Generator;

/**
 * Token-based editor that adds a class reference to a list inside a `#[Module(...)]` attribute.
 * Idempotent: adding an already-listed class is a no-op.
 *
 *   ModuleEditor::register('/app/src/Cats/CatsModule.php', 'controllers', 'App\Cats\CatsController');
 *   // #[Module]                                → #[Module(controllers: [CatsController::class])]
 *   // #[Module(providers: [A::class])]         → #[Module(providers: [A::class], controllers: [CatsController::class])]
 *   // #[Module(controllers: [A::class])]       → #[Module(controllers: [A::class, CatsController::class])]
 *
 * A `use` statement is added when the class lives in another namespace.
 */
final class ModuleEditor
{
    /** Returns true when the file changed, false when the class was already registered. */
    public static function register(string $file, string $key, string $fqcn): bool
    {
        if (! is_file($file)) {
            throw new \RuntimeException("Module file $file does not exist.");
        }
        $code = (string) file_get_contents($file);
        $updated = self::addToList($code, $key, $fqcn);
        if ($updated === $code) {
            return false;
        }
        file_put_contents($file, $updated);

        return true;
    }

    /**
     * Add an expression built from a class, e.g. `new %s(isGlobal: true)` (`%s` = the name the file uses for $fqcn).
     * No-op when the attribute already mentions the class in any form (`X::class`, `new X(...)`, `[X::class, 'forRoot']`).
     */
    public static function registerExpression(string $file, string $key, string $fqcn, string $expression): bool
    {
        if (! is_file($file)) {
            throw new \RuntimeException("Module file $file does not exist.");
        }
        $code = (string) file_get_contents($file);
        $updated = self::addExpressionToList($code, $key, $fqcn, $expression);
        if ($updated === $code) {
            return false;
        }
        file_put_contents($file, $updated);

        return true;
    }

    /** Pure version of register(): returns the modified source. */
    public static function addToList(string $code, string $key, string $fqcn): string
    {
        return self::add($code, $key, $fqcn, '%s::class', '::class');
    }

    /** Pure version of registerExpression(): returns the modified source. */
    public static function addExpressionToList(string $code, string $key, string $fqcn, string $expression): string
    {
        return self::add($code, $key, $fqcn, $expression, '\\b');
    }

    /** $listedSuffix: regex after the class name that counts as "already listed". */
    private static function add(string $code, string $key, string $fqcn, string $expression, string $listedSuffix): string
    {
        $fqcn = ltrim($fqcn, '\\');
        $short = str_contains($fqcn, '\\') ? substr($fqcn, strrpos($fqcn, '\\') + 1) : $fqcn;

        $attr = self::findModuleAttribute($code);
        if ($attr === null) {
            throw new \RuntimeException('No #[Module] attribute found in the module file.');
        }
        [$start, $end] = $attr; // offsets of "#[" and the closing "]" (inclusive)
        $body = substr($code, $start, $end - $start + 1);
        [$local, $needsUse] = self::localName($code, $fqcn, $short);
        $listed = ! $needsUse && preg_match('/(?<![A-Za-z0-9_\\\\])' . preg_quote($local, '/') . $listedSuffix . '/', $body);
        if ($listed || preg_match('/\\\\' . preg_quote($fqcn, '/') . $listedSuffix . '/', $body)) {
            return $code;
        }
        $reference = sprintf($expression, $local);

        $newBody = self::insertIntoAttribute($body, $key, $reference);
        $code = substr($code, 0, $start) . $newBody . substr($code, $end + 1);

        return $needsUse ? self::ensureUse($code, $fqcn, $local === $short ? null : $local) : $code;
    }

    /**
     * The name the file uses (or will use) for $fqcn, and whether a `use` statement is still needed.
     * When the short name already refers to another class (App\Mail\MailModule vs Acme\Mail\MailModule)
     * the new import gets an alias built from its vendor namespace: AcmeMailModule.
     *
     * @return array{0: string, 1: bool}
     */
    private static function localName(string $code, string $fqcn, string $short): array
    {
        $namespace = preg_match('/^namespace\s+([^;]+);/m', $code, $m) ? trim($m[1]) : '';
        $classNamespace = str_contains($fqcn, '\\') ? substr($fqcn, 0, strrpos($fqcn, '\\')) : '';
        if ($classNamespace === $namespace) {
            return [$short, false];
        }
        if (preg_match('/^use\s+' . preg_quote($fqcn, '/') . '(?:\s+as\s+(\w+))?\s*;/mi', $code, $existing)) {
            return [$existing[1] ?? $short, false];
        }

        $taken = [];
        preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $code, $uses, PREG_SET_ORDER);
        foreach ($uses as $use) {
            $taken[strtolower($use[2] ?? substr((string) strrchr('\\' . $use[1], '\\'), 1))] = true;
        }
        if (preg_match_all('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $code, $declared)) {
            foreach ($declared[1] as $name) {
                $taken[strtolower($name)] = true;
            }
        }
        if (! isset($taken[strtolower($short)])) {
            return [$short, true];
        }

        $base = str_replace('\\', '', (string) strstr($fqcn, '\\', true)) . $short;
        $alias = $base;
        for ($i = 2; isset($taken[strtolower($alias)]); $i++) {
            $alias = $base . $i;
        }

        return [$alias, true];
    }

    /** @return array{0: int, 1: int}|null */
    private static function findModuleAttribute(string $code): ?array
    {
        if (! preg_match('/#\[\s*(?:\\\\?(?:Nesto\\\\Common\\\\Attributes\\\\)?)Module\b/', $code, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = $m[0][1];
        $depth = 0;
        $len = strlen($code);
        $i = $start;
        $quote = null;
        for (; $i < $len; $i++) {
            $ch = $code[$i];
            if ($quote !== null) {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '\'' || $ch === '"') {
                $quote = $ch;
                continue;
            }
            if ($ch === '[' || $ch === '(') {
                $depth++;
            } elseif ($ch === ']' || $ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return [$start, $i];
                }
            }
        }

        return null;
    }

    private static function insertIntoAttribute(string $body, string $key, string $reference): string
    {
        // #[Module] with no argument list
        if (! preg_match('/Module\s*\(/', $body)) {
            return preg_replace('/Module(\s*)\]$/', "Module($key: [$reference])]", $body) ?? $body;
        }

        // existing named argument?
        if (preg_match('/\b' . preg_quote($key, '/') . '\s*:\s*\[/', $body, $m, PREG_OFFSET_CAPTURE)) {
            $open = $m[0][1] + strlen($m[0][0]) - 1;
            $close = self::matchingBracket($body, $open);
            $inner = substr($body, $open + 1, $close - $open - 1);
            if (trim($inner) === '') {
                return substr($body, 0, $open + 1) . $reference . substr($body, $close);
            }
            if (str_contains($inner, "\n")) {
                $indent = preg_match('/\n([ \t]*)\S/', $inner, $im) ? $im[1] : '        ';
                $trimmed = rtrim($inner);
                $needsComma = ! str_ends_with($trimmed, ',');
                $tail = substr($inner, strlen($trimmed));
                $newInner = $trimmed . ($needsComma ? ',' : '') . "\n" . $indent . $reference . ',' . $tail;
            } else {
                $newInner = rtrim($inner, ', ') . ', ' . $reference;
            }

            return substr($body, 0, $open + 1) . $newInner . substr($body, $close);
        }

        // add a new named argument at the end of the argument list
        $parenOpen = strpos($body, '(');
        $parenClose = self::matchingBracket($body, (int) $parenOpen);
        $args = substr($body, $parenOpen + 1, $parenClose - $parenOpen - 1);
        if (trim($args) === '') {
            $newArgs = "$key: [$reference]";
        } elseif (str_contains($args, "\n")) {
            $indent = preg_match('/\n([ \t]*)\S/', $args, $im) ? $im[1] : '    ';
            $trimmed = rtrim($args);
            $needsComma = ! str_ends_with($trimmed, ',');
            $tail = substr($args, strlen($trimmed));
            $newArgs = $trimmed . ($needsComma ? ',' : '') . "\n" . $indent . "$key: [$reference]," . $tail;
        } else {
            $newArgs = rtrim($args, ', ') . ", $key: [$reference]";
        }

        return substr($body, 0, $parenOpen + 1) . $newArgs . substr($body, $parenClose);
    }

    private static function matchingBracket(string $code, int $open): int
    {
        $pairs = ['[' => ']', '(' => ')'];
        $closeChar = $pairs[$code[$open]] ?? ']';
        $depth = 0;
        $quote = null;
        $len = strlen($code);
        for ($i = $open; $i < $len; $i++) {
            $ch = $code[$i];
            if ($quote !== null) {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '\'' || $ch === '"') {
                $quote = $ch;
                continue;
            }
            if ($ch === '[' || $ch === '(') {
                $depth++;
            } elseif ($ch === ']' || $ch === ')') {
                $depth--;
                if ($depth === 0 && $ch === $closeChar) {
                    return $i;
                }
            }
        }
        throw new \RuntimeException('Unbalanced brackets in #[Module] attribute.');
    }

    private static function ensureUse(string $code, string $fqcn, ?string $alias = null): string
    {
        $useLine = $alias === null ? "use $fqcn;" : "use $fqcn as $alias;";
        if (preg_match_all('/^use\s+[^;]+;\s*$/m', $code, $uses, PREG_OFFSET_CAPTURE) && $uses[0] !== []) {
            // insert keeping alphabetical order among existing use statements
            $inserted = false;
            foreach ($uses[0] as [$line, $offset]) {
                if (strcasecmp($line, $useLine) > 0) {
                    $code = substr($code, 0, $offset) . $useLine . "\n" . substr($code, $offset);
                    $inserted = true;
                    break;
                }
            }
            if (! $inserted) {
                [$line, $offset] = end($uses[0]);
                $pos = $offset + strlen(rtrim($line));
                $code = substr($code, 0, $pos) . "\n" . $useLine . substr($code, $pos);
            }

            return $code;
        }
        if (preg_match('/^namespace\s+[^;]+;\s*$/m', $code, $nm, PREG_OFFSET_CAPTURE)) {
            $pos = $nm[0][1] + strlen(rtrim($nm[0][0]));

            return substr($code, 0, $pos) . "\n\n" . $useLine . substr($code, $pos);
        }

        return $code;
    }
}
