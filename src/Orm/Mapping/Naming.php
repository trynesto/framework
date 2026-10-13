<?php

declare(strict_types=1);

namespace Nesto\Orm\Mapping;

/** Default naming strategy: snake_case columns, snake_case plural tables. */
final class Naming
{
    public static function snake(string $name): string
    {
        $name = (string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $name);
        $name = (string) preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $name);

        return strtolower($name);
    }

    public static function plural(string $word): string
    {
        if (preg_match('/[^aeiou]y$/i', $word)) {
            return substr($word, 0, -1) . 'ies';
        }
        if (preg_match('/(s|x|z|ch|sh)$/i', $word)) {
            return $word . 'es';
        }

        return $word . 's';
    }

    public static function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    public static function tableFor(string $class): string
    {
        return self::plural(self::snake(self::shortName($class)));
    }
}
