<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client;

use Closure;

/**
 * Prisma's `Prisma` namespace helpers:
 *
 *   Prisma::sql('SELECT * FROM "User" WHERE id = ', $id)       // tagged-template-like raw SQL (Sql)
 *   Prisma::join([1, 2, 3]), Prisma::raw('"User"'), Prisma::empty()
 *   Prisma::JsonNull, Prisma::DbNull, Prisma::AnyNull            // null kinds of Json fields
 *   Prisma::defineExtension([...])                               // a reusable $extends() argument
 *   Prisma::ReadCommitted ... Prisma::Serializable               // TransactionIsolationLevel
 */
final class Prisma
{
    public const JsonNull = NullType::JsonNull;
    public const DbNull = NullType::DbNull;
    public const AnyNull = NullType::AnyNull;

    public const ReadUncommitted = 'ReadUncommitted';
    public const ReadCommitted = 'ReadCommitted';
    public const RepeatableRead = 'RepeatableRead';
    public const Serializable = 'Serializable';

    /** Template form: text, value, text, value, ... */
    public static function sql(mixed ...$parts): Sql
    {
        return Sql::template(...$parts);
    }

    /** @param array<mixed> $values */
    public static function join(array $values, string $separator = ', ', string $prefix = '', string $suffix = ''): Sql
    {
        return Sql::join($values, $separator, $prefix, $suffix);
    }

    public static function raw(string $sql): Sql
    {
        return Sql::raw($sql);
    }

    public static function empty(): Sql
    {
        return Sql::empty();
    }

    /**
     * An extension to pass to `$prisma->extends()`: an array of components (`name`, `model`, `query`, `result`,
     * `client`) or a closure receiving the client and returning an extended client.
     *
     * @param array<string, mixed>|Closure $extension
     * @return array<string, mixed>|Closure
     */
    public static function defineExtension(array|Closure $extension): array|Closure
    {
        return $extension;
    }
}
