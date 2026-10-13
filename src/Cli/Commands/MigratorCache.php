<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

/**
 * Process-wide cache of ORM Migrator instances keyed by DataSource.
 * The ORM registers migration classes the first time a file is required, so every command in one
 * process must reuse the same Migrator (matters for tests and for commands calling other commands).
 */
final class MigratorCache
{
    /** @var \WeakMap<object, object>|null */
    private static ?\WeakMap $map = null;

    public static function get(object $dataSource): ?object
    {
        self::$map ??= new \WeakMap();

        return self::$map[$dataSource] ?? null;
    }

    public static function put(object $dataSource, object $migrator): object
    {
        self::$map ??= new \WeakMap();
        self::$map[$dataSource] = $migrator;

        return $migrator;
    }
}
