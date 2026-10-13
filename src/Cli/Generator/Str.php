<?php

declare(strict_types=1);

namespace Nesto\Cli\Generator;

/**
 * Case helpers used by the generators.
 *
 *   Str::pascal('user-profiles');   // 'UserProfiles'
 *   Str::camel('user-profiles');    // 'userProfiles'
 *   Str::kebab('UserProfiles');     // 'user-profiles'
 *   Str::snake('UserProfiles');     // 'user_profiles'
 *   Str::singular('categories');    // 'category'
 *   Str::plural('cat');             // 'cats'
 */
final class Str
{
    /** @return list<string> */
    public static function words(string $value): array
    {
        $value = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $value) ?? $value;
        $value = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $value) ?? $value;
        $parts = preg_split('/[\s_\-\.]+/', $value) ?: [];

        return array_values(array_filter(array_map('strtolower', $parts), fn ($p) => $p !== ''));
    }

    public static function pascal(string $value): string
    {
        return implode('', array_map('ucfirst', self::words($value)));
    }

    public static function camel(string $value): string
    {
        return lcfirst(self::pascal($value));
    }

    public static function kebab(string $value): string
    {
        return implode('-', self::words($value));
    }

    public static function snake(string $value): string
    {
        return implode('_', self::words($value));
    }

    public static function singular(string $value): string
    {
        $lower = strtolower($value);
        $irregular = ['people' => 'person', 'children' => 'child', 'men' => 'man', 'women' => 'woman', 'mice' => 'mouse', 'geese' => 'goose', 'feet' => 'foot', 'teeth' => 'tooth'];
        if (isset($irregular[$lower])) {
            return self::matchCase($value, $irregular[$lower]);
        }
        $uncountable = ['news', 'series', 'species', 'sheep', 'fish', 'data', 'info', 'status', 'analysis'];
        if (in_array($lower, $uncountable, true)) {
            return $value;
        }
        $rules = [
            '/(quiz)zes$/i' => '$1',
            '/(matr)ices$/i' => '$1ix',
            '/(vert|ind)ices$/i' => '$1ex',
            '/^(ox)en$/i' => '$1',
            '/(alias|status)es$/i' => '$1',
            '/([octop|vir])i$/i' => '$1us',
            '/(cris|ax|test)es$/i' => '$1is',
            '/(shoe)s$/i' => '$1',
            '/(o)es$/i' => '$1',
            '/(bus)es$/i' => '$1',
            '/([m|l])ice$/i' => '$1ouse',
            '/(x|ch|ss|sh)es$/i' => '$1',
            '/(m)ovies$/i' => '$1ovie',
            '/(s)eries$/i' => '$1eries',
            '/([^aeiouy]|qu)ies$/i' => '$1y',
            '/([lr])ves$/i' => '$1f',
            '/(tive)s$/i' => '$1',
            '/(hive)s$/i' => '$1',
            '/([^f])ves$/i' => '$1fe',
            '/(^analy)ses$/i' => '$1sis',
            '/([ti])a$/i' => '$1um',
            '/(n)ews$/i' => '$1ews',
            '/(ss)$/i' => '$1',
            '/s$/i' => '',
        ];
        foreach ($rules as $pattern => $replacement) {
            if (preg_match($pattern, $value)) {
                return preg_replace($pattern, $replacement, $value) ?? $value;
            }
        }

        return $value;
    }

    public static function plural(string $value): string
    {
        $lower = strtolower($value);
        $irregular = ['person' => 'people', 'child' => 'children', 'man' => 'men', 'woman' => 'women', 'mouse' => 'mice', 'goose' => 'geese', 'foot' => 'feet', 'tooth' => 'teeth'];
        if (isset($irregular[$lower])) {
            return self::matchCase($value, $irregular[$lower]);
        }
        if (in_array($lower, ['news', 'series', 'species', 'sheep', 'fish', 'data', 'info'], true)) {
            return $value;
        }
        $rules = [
            '/(quiz)$/i' => '$1zes',
            '/^(ox)$/i' => '$1en',
            '/([m|l])ouse$/i' => '$1ice',
            '/(matr|vert|ind)ix|ex$/i' => '$1ices',
            '/(x|ch|ss|sh)$/i' => '$1es',
            '/([^aeiouy]|qu)y$/i' => '$1ies',
            '/(hive)$/i' => '$1s',
            '/(?:([^f])fe|([lr])f)$/i' => '$1$2ves',
            '/sis$/i' => 'ses',
            '/([ti])um$/i' => '$1a',
            '/(buffal|tomat|potat)o$/i' => '$1oes',
            '/(bu)s$/i' => '$1ses',
            '/(alias|status)$/i' => '$1es',
            '/(octop|vir)us$/i' => '$1i',
            '/(ax|test)is$/i' => '$1es',
            '/s$/i' => 's',
            '/$/' => 's',
        ];
        foreach ($rules as $pattern => $replacement) {
            if (preg_match($pattern, $value)) {
                return preg_replace($pattern, $replacement, $value) ?? $value;
            }
        }

        return $value . 's';
    }

    private static function matchCase(string $original, string $replacement): string
    {
        return ctype_upper($original[0] ?? '') ? ucfirst($replacement) : $replacement;
    }
}
