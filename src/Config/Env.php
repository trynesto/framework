<?php

declare(strict_types=1);

namespace Nesto\Config;

/**
 * Minimal dotenv implementation (no external dependency).
 *
 * Supported syntax:
 *   # comments and blank lines
 *   KEY=value                    unquoted (trailing " # comment" stripped, surrounding whitespace trimmed)
 *   KEY="value\n${OTHER}"        double quotes: escapes (\n \t \r \" \\ \$) and ${VAR} / ${VAR:-default} / $VAR expansion
 *                                (an unknown ${VAR} becomes its default or ""; an unknown bare $VAR is kept verbatim)
 *   KEY='literal ${NOT_EXPANDED}' single quotes: taken verbatim
 *   export KEY=value             "export " prefix is ignored
 *   KEY=                         empty string
 *
 * Usage:
 *   Env::load('.env');                      // populates $_ENV, $_SERVER and putenv(), never overriding existing vars
 *   Env::load(['.env', '.env.local'], override: true);
 *   $vars = Env::parse("A=1\nB=\${A}2");    // ['A' => '1', 'B' => '12'] (pure, no side effects)
 */
final class Env
{
    /**
     * Load one or more files into the environment. Missing files are skipped silently.
     *
     * @param string|list<string> $paths
     * @return array<string, string> every variable found in the files (last file wins)
     */
    public static function load(string|array $paths, bool $override = false): array
    {
        $all = [];
        foreach ((array) $paths as $path) {
            if (! is_file($path) || ! is_readable($path)) {
                continue;
            }
            $vars = self::parse((string) file_get_contents($path), $all);
            self::populate($vars, $override);
            $all = array_replace($all, $vars);
        }

        return $all;
    }

    /**
     * Parse dotenv content into a map. Variables referenced with ${VAR} are looked up first in
     * the parsed content (and $extra), then in the real environment.
     *
     * @param array<string, string> $extra values visible to ${VAR} expansion but not returned
     * @return array<string, string>
     */
    public static function parse(string $content, array $extra = []): array
    {
        $vars = [];
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = trim($lines[$i]);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue; // "KEY" without "=" → ignored (bash-style export of an existing var)
            }
            $key = trim(substr($line, 0, $eq));
            if ($key === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $key)) {
                continue;
            }
            $raw = ltrim(substr($line, $eq + 1));

            // multi-line quoted values: keep appending lines until the closing quote shows up
            if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'") && ! self::hasClosingQuote($raw)) {
                while ($i + 1 < $count) {
                    $raw .= "\n" . $lines[++$i];
                    if (self::hasClosingQuote($raw)) {
                        break;
                    }
                }
            }

            $vars[$key] = self::value($raw, $vars + $extra);
        }

        return $vars;
    }

    /**
     * Write variables into $_ENV, $_SERVER and putenv().
     *
     * @param array<string, scalar|null> $vars
     */
    public static function populate(array $vars, bool $override = false): void
    {
        foreach ($vars as $key => $value) {
            if (! $override && self::exists($key)) {
                continue;
            }
            $string = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $_ENV[$key] = $string;
            $_SERVER[$key] = $string;
            putenv("$key=$string");
        }
    }

    /** True when the variable is already defined in $_ENV, $_SERVER or the process environment. */
    public static function exists(string $key): bool
    {
        return array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER) || getenv($key) !== false;
    }

    /** Read a variable from $_ENV / $_SERVER / getenv() (raw string, null when undefined). */
    public static function get(string $key): ?string
    {
        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key] === null ? null : (string) $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER) && is_scalar($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        $v = getenv($key);

        return $v === false ? null : $v;
    }

    /**
     * Cast an env string the way the env() helper does: "true"/"false"/"null"/"empty" and numerics.
     */
    public static function cast(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }

    // ---- internals ------------------------------------------------------------

    private static function hasClosingQuote(string $raw): bool
    {
        $quote = $raw[0];
        $len = strlen($raw);
        for ($i = 1; $i < $len; $i++) {
            if ($raw[$i] === '\\' && $quote === '"') {
                $i++;
                continue;
            }
            if ($raw[$i] === $quote) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $known */
    private static function value(string $raw, array $known): string
    {
        if ($raw === '') {
            return '';
        }
        $quote = $raw[0];

        if ($quote === "'") {
            $end = strpos($raw, "'", 1);

            return $end === false ? substr($raw, 1) : substr($raw, 1, $end - 1);
        }

        if ($quote === '"') {
            $out = '';
            $len = strlen($raw);
            for ($i = 1; $i < $len; $i++) {
                $c = $raw[$i];
                if ($c === '\\' && $i + 1 < $len) {
                    $n = $raw[++$i];
                    $out .= match ($n) {
                        'n' => "\n", 't' => "\t", 'r' => "\r", '"' => '"', '\\' => '\\', '$' => "\0DOLLAR\0",
                        default => '\\' . $n,
                    };
                    continue;
                }
                if ($c === '"') {
                    break;
                }
                $out .= $c;
            }

            return str_replace("\0DOLLAR\0", '$', self::expand($out, $known));
        }

        // unquoted: strip inline comment (" #") and trailing whitespace
        $hash = preg_match('/\s#/', $raw, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : false;
        if ($hash !== false) {
            $raw = substr($raw, 0, $hash);
        }

        return self::expand(trim($raw), $known);
    }

    /** @param array<string, string> $known */
    private static function expand(string $value, array $known): string
    {
        if (! str_contains($value, '$')) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)(?::-([^}]*))?\}|\$([A-Za-z_][A-Za-z0-9_]*)/',
            function (array $m) use ($known): string {
                $braced = $m[1] !== '';
                $name = $braced ? $m[1] : ($m[3] ?? '');
                if (array_key_exists($name, $known)) {
                    return $known[$name];
                }
                $value = self::get($name);
                if ($value !== null) {
                    return $value;
                }

                // ${VAR} / ${VAR:-default} → default (empty); bare $VAR that is unknown stays literal (passwords with "$")
                return $braced ? ($m[2] ?? '') : $m[0];
            },
            $value
        );
    }
}
