<?php

declare(strict_types=1);

namespace Nesto\Orm\Schema;

use Nesto\Orm\SqlExpression;

/**
 * Canonical forms used to compare the schema the entities want with the one the database reports, so an
 * unchanged entity produces no diff on any platform: synonyms of a type collapse to one name ("int4",
 * "integer", "serial" → "int"), defaults are compared by value (`'a'::character varying`, `_utf8mb4\'a\'`
 * and `'a'` are the same string; `now()` is `current_timestamp`), referential actions by meaning.
 */
final class Normalizer
{
    private const INTEGER_TYPES = ['int', 'bigint', 'smallint', 'tinyint', 'mediumint', 'integer'];
    private const NUMERIC_TYPES = ['int', 'bigint', 'smallint', 'tinyint', 'mediumint', 'integer', 'decimal', 'numeric', 'float', 'double', 'real', 'float4', 'float8'];

    /**
     * Canonical type of an SQL type string on a platform ("VARCHAR(255)", "character varying(255)" →
     * "varchar(255)"). The unsigned flag of MySQL types is returned separately.
     *
     * @return array{0: string, 1: bool} [type, unsigned]
     */
    public static function type(string $platform, string $sql): array
    {
        $type = strtolower(trim($sql));
        $unsigned = false;
        if (preg_match('/\s+unsigned\b/', $type) === 1) {
            $unsigned = true;
            $type = trim((string) preg_replace('/\s+(unsigned|zerofill)\b/', '', $type));
        }
        $type = self::stripWhitespace($type);
        if (preg_match('/^"([^"]+)"$/', $type, $quoted) === 1) {
            return [$quoted[1], $unsigned]; // a quoted user type (PostgreSQL native enum)
        }
        // "timestamp(3) without time zone" → base + params + suffix
        if (! preg_match('/^([a-z_][a-z0-9_ ."]*?)(?:\((.*)\))?((?:withtimezone|withouttimezone)?)(\[\])?$/s', $type, $m)) {
            return [$type, $unsigned];
        }
        $base = trim($m[1], '"');
        $params = $m[2] ?? '';
        $zone = $m[3] ?? '';
        $array = $m[4] ?? '';
        $base = match ($base) {
            'charactervarying' => 'varchar',
            'character' => 'char',
            'doubleprecision' => 'double',
            default => $base,
        };
        if (in_array($base, ['timestamp', 'time'], true) && $zone === 'withtimezone') {
            $base .= 'tz';
        }

        $canonical = match ($platform) {
            'pgsql' => self::pgsqlType($base, $params),
            'mysql', 'mariadb' => self::mysqlType($base, $params, $platform),
            default => $params === '' ? $base : $base . '(' . $params . ')',
        };

        return [$canonical . $array, $unsigned];
    }

    private static function pgsqlType(string $base, string $params): string
    {
        $base = match ($base) {
            'int', 'int4', 'integer', 'serial', 'serial4' => 'int',
            'int8', 'bigserial', 'serial8' => 'bigint',
            'int2', 'smallserial', 'serial2' => 'smallint',
            'decimal' => 'numeric',
            'float8', 'double', 'float' => $params !== '' && (int) $params <= 24 ? 'real' : 'double',
            'float4' => 'real',
            'bool' => 'boolean',
            'timestamptz' => 'timestamptz',
            'timetz' => 'timetz',
            default => $base,
        };
        if (in_array($base, ['int', 'bigint', 'smallint', 'double', 'real', 'boolean'], true)) {
            return $base;
        }
        if (in_array($base, ['timestamp', 'timestamptz', 'time', 'timetz'], true)) {
            return $params === '' || $params === '6' ? $base : $base . '(' . $params . ')';
        }

        return $params === '' ? $base : $base . '(' . $params . ')';
    }

    private static function mysqlType(string $base, string $params, string $platform): string
    {
        if ($base === 'bool' || $base === 'boolean' || ($base === 'tinyint' && $params === '1')) {
            return 'tinyint(1)'; // the boolean type: its width is meaningful
        }
        $base = match ($base) {
            'integer' => 'int',
            'double', 'real' => 'double',
            'numeric' => 'decimal',
            'json' => $platform === 'mariadb' ? 'longtext' : 'json',
            default => $base,
        };
        if (in_array($base, ['int', 'bigint', 'smallint', 'mediumint', 'tinyint'], true)) {
            return $base; // display widths (int(11)) carry no meaning
        }
        if ($base === 'decimal') {
            $parts = $params === '' ? ['10', '0'] : explode(',', $params) + [1 => '0'];

            return 'decimal(' . $parts[0] . ',' . $parts[1] . ')';
        }
        if (in_array($base, ['datetime', 'timestamp', 'time'], true) && $params === '0') {
            return $base;
        }

        return $params === '' ? $base : $base . '(' . $params . ')';
    }

    /** Canonical type name of a column as Grammar::typeSql() renders it (or its introspected raw type). */
    public static function isNumeric(string $canonicalType): bool
    {
        $base = (string) preg_replace('/\(.*$/', '', $canonicalType);

        return in_array($base, self::NUMERIC_TYPES, true);
    }

    public static function isInteger(string $canonicalType): bool
    {
        return in_array((string) preg_replace('/\(.*$/', '', $canonicalType), self::INTEGER_TYPES, true);
    }

    public static function isBoolean(string $canonicalType): bool
    {
        return $canonicalType === 'boolean' || $canonicalType === 'tinyint(1)' || $canonicalType === 'bool';
    }

    public static function isJson(string $canonicalType): bool
    {
        return in_array($canonicalType, ['json', 'jsonb'], true);
    }

    /**
     * Comparable form of a column default: null when there is none (DEFAULT NULL counts as none),
     * "x:<expression>" for raw SQL, otherwise the value as a string (numbers normalized, booleans "1"/"0",
     * JSON re-encoded).
     */
    public static function defaultValue(Column $column, string $canonicalType): ?string
    {
        if (! $column->hasDefault || $column->default === null || $column->autoIncrement) {
            return null;
        }
        $value = $column->default;
        if (is_string($value) && strcasecmp($value, 'CURRENT_TIMESTAMP') === 0) {
            $value = new SqlExpression($value); // Grammar writes this string as the SQL keyword
        }
        if ($value instanceof SqlExpression) {
            $expression = self::expression($value->sql);
            if ($expression === 'null') {
                return null;
            }
            $literal = self::literalOf($expression);

            return $literal !== null ? self::defaultValueOf($literal, $canonicalType) : 'x:' . $expression;
        }

        return self::defaultValueOf($value, $canonicalType);
    }

    private static function defaultValueOf(mixed $value, string $canonicalType): string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $string = (string) $value;
        if (self::isBoolean($canonicalType) && in_array(strtolower($string), ['true', 'false', 't', 'f', '1', '0'], true)) {
            return in_array(strtolower($string), ['true', 't', '1'], true) ? '1' : '0';
        }
        if ((self::isNumeric($canonicalType) || $canonicalType === 'integer' || $canonicalType === 'real') && is_numeric($string)) {
            return self::number($string);
        }
        if (self::isJson($canonicalType) || $canonicalType === 'longtext') {
            $decoded = json_decode($string, true);
            if ($decoded !== null || $string === 'null') {
                return (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return $string;
    }

    /**
     * Comparable form of a generated column expression as the entity declares it and as each database
     * reports it back (MySQL adds backticks and charset introducers, PostgreSQL casts and parentheses):
     * lower-cased, without whitespace, quotes, casts, introducers and parentheses.
     */
    public static function generationExpression(string $sql): string
    {
        $expression = self::expression($sql);
        $expression = (string) preg_replace("/_[a-z0-9]+(\\\\)?'/", "'", $expression); // _utf8mb4'x' (MySQL), _utf8mb4\'x\' (escaped)
        $expression = str_replace(["\\'", '(', ')'], ["'", '', ''], $expression);

        return $expression;
    }

    /** "1.50" → "1.5", "0.00" → "0", "+3" → "3". */
    public static function number(string $number): string
    {
        $number = ltrim(trim($number), '+');
        if (str_contains($number, 'e') || str_contains($number, 'E')) {
            $number = rtrim(rtrim(sprintf('%.12F', (float) $number), '0'), '.');
        }
        if (str_contains($number, '.')) {
            $number = rtrim(rtrim($number, '0'), '.');
        }
        $negative = str_starts_with($number, '-');
        $number = ltrim($negative ? substr($number, 1) : $number, '0');
        if ($number === '' || str_starts_with($number, '.')) {
            $number = '0' . $number;
        }

        return $number === '0' ? '0' : ($negative ? '-' : '') . $number;
    }

    /** Lower-cased SQL expression without whitespace, identifier quotes, casts and redundant parentheses. */
    public static function expression(string $sql): string
    {
        $out = '';
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($char === "'") {
                // copy string literals verbatim
                $end = $i + 1;
                while ($end < $length) {
                    if ($sql[$end] === "'" && ($sql[$end + 1] ?? '') === "'") {
                        $end += 2;
                        continue;
                    }
                    if ($sql[$end] === "'") {
                        break;
                    }
                    $end++;
                }
                $out .= substr($sql, $i, $end - $i + 1);
                $i = $end;
                continue;
            }
            if (ctype_space($char) || $char === '"' || $char === '`') {
                continue;
            }
            $out .= strtolower($char);
        }
        // PostgreSQL casts of literals ('a'::character varying, 0::numeric) and type-less parentheses
        $out = (string) preg_replace("/::[a-z_ ]+(\[\])?(\(\d+(,\d+)?\))?/", '', $out);
        $out = (string) preg_replace('/\b(current_timestamp|now|localtimestamp|current_date|current_time)\(\)/', '$1', $out);
        $out = (string) preg_replace('/\b(now|localtimestamp)\b/', 'current_timestamp', $out);
        while (str_starts_with($out, '(') && str_ends_with($out, ')') && self::balanced(substr($out, 1, -1))) {
            $out = substr($out, 1, -1);
        }

        return $out;
    }

    /** The value of a normalized expression that is a single literal ('abc', 42, -1.5, true), else null. */
    private static function literalOf(string $expression): mixed
    {
        if (preg_match("/^_[a-z0-9]+\\\\?'(.*)\\\\?'$/s", $expression, $m) === 1) {
            return str_replace(["\\'", "''"], "'", $m[1]); // MySQL charset introducer: _utf8mb4'...'
        }
        if (preg_match("/^'((?:[^']|'')*)'$/s", $expression, $m) === 1) {
            return str_replace("''", "'", $m[1]);
        }
        if (is_numeric($expression)) {
            return $expression;
        }
        if ($expression === 'true' || $expression === 'false') {
            return $expression === 'true';
        }

        return null;
    }

    /**
     * Parse an introspected DEFAULT clause into a PHP value (string / int / float / bool), a SqlExpression
     * for anything else, or null for NULL / none.
     */
    public static function parseDefault(?string $sql): mixed
    {
        if ($sql === null) {
            return null;
        }
        $trimmed = trim($sql);
        if ($trimmed === '' || strtoupper($trimmed) === 'NULL' || preg_match('/^NULL::/i', $trimmed) === 1) {
            return null;
        }
        $stripped = (string) preg_replace("/::[a-z_ ]+(\\[\\])?(\\(\\d+(,\\s*\\d+)?\\))?$/i", '', $trimmed);
        while (str_starts_with($stripped, '(') && str_ends_with($stripped, ')') && self::balanced(substr($stripped, 1, -1))) {
            $stripped = trim(substr($stripped, 1, -1));
            $stripped = (string) preg_replace("/::[a-z_ ]+(\\[\\])?(\\(\\d+(,\\s*\\d+)?\\))?$/i", '', $stripped);
        }
        if (preg_match("/^_[a-z0-9]+\\\\'(.*)\\\\'$/s", $stripped, $m) === 1) {
            return str_replace("\\'", "'", $m[1]);
        }
        if (preg_match("/^'((?:[^']|'')*)'$/s", $stripped, $m) === 1) {
            return str_replace("''", "'", $m[1]);
        }
        if (preg_match('/^-?\d+$/', $stripped) === 1 && strlen(ltrim($stripped, '-')) < 19) {
            return (int) $stripped;
        }
        if (is_numeric($stripped)) {
            return $stripped;
        }
        if (strcasecmp($stripped, 'true') === 0 || strcasecmp($stripped, 'false') === 0) {
            return strcasecmp($stripped, 'true') === 0;
        }

        return new SqlExpression($stripped);
    }

    /** NO ACTION is the default action (RESTRICT too on MySQL/MariaDB, where they are the same). */
    public static function action(string $platform, ?string $action): string
    {
        $action = strtoupper(trim((string) $action));
        if ($action === '' || $action === 'NO ACTION' || ($action === 'RESTRICT' && in_array($platform, ['mysql', 'mariadb'], true))) {
            return 'NO ACTION';
        }

        return $action === 'DEFAULT' ? 'SET DEFAULT' : $action;
    }

    /** @param list<string> $a @param list<string> $b */
    public static function sameColumns(array $a, array $b): bool
    {
        return array_map('strtolower', array_values($a)) === array_map('strtolower', array_values($b));
    }

    public static function stripWhitespace(string $sql): string
    {
        $out = '';
        $quoted = false;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($char === "'") {
                $quoted = ! $quoted;
            }
            if (! $quoted && ctype_space($char)) {
                continue;
            }
            $out .= $char;
        }

        return $out;
    }

    private static function balanced(string $sql): bool
    {
        $depth = 0;
        $quoted = false;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($char === "'") {
                $quoted = ! $quoted;
            } elseif (! $quoted && $char === '(') {
                $depth++;
            } elseif (! $quoted && $char === ')') {
                if (--$depth < 0) {
                    return false;
                }
            }
        }

        return $depth === 0;
    }
}
