<?php

declare(strict_types=1);

namespace Nesto\Orm\Types;

use Nesto\Orm\OrmException;

/**
 * Named custom column types (Doctrine's Type::addType()). Registration is application configuration, done
 * once at boot (it survives requests in worker mode like any other config); a type class can also be used
 * directly as #[Column(type: MoneyType::class)] without registering it.
 *
 *   TypeRegistry::register('money', MoneyType::class);
 *   TypeRegistry::has('money');   TypeRegistry::get('money');   // the shared instance
 */
final class TypeRegistry
{
    /** @var array<string, ColumnType> */
    private static array $types = [];

    /** @param class-string<ColumnType>|ColumnType $type */
    public static function register(string $name, string|ColumnType $type): void
    {
        $key = strtolower($name);
        if (is_string($type)) {
            if (! is_subclass_of($type, ColumnType::class)) {
                throw new OrmException("Custom column type \"$name\": $type must extend " . ColumnType::class . '.');
            }
            $type = new $type;
        }
        self::$types[$key] = $type;
    }

    /** The type for a #[Column] type name (a registered name or a ColumnType class), null for built-in types. */
    public static function get(?string $name): ?ColumnType
    {
        if ($name === null || $name === '') {
            return null;
        }
        $key = strtolower($name);
        if (isset(self::$types[$key])) {
            return self::$types[$key];
        }
        if (str_contains($name, '\\') && is_subclass_of($name, ColumnType::class)) {
            return self::$types[$key] = new $name;
        }

        return null;
    }

    public static function has(string $name): bool
    {
        return self::get($name) !== null;
    }

    public static function unregister(string $name): void
    {
        unset(self::$types[strtolower($name)]);
    }
}
