<?php

declare(strict_types=1);

namespace Nesto\Orm\Operators;

use Closure;

/**
 * TypeORM's find operators as static methods (the functions in Nesto\Orm\Operators are shortcuts to
 * them; `and` / `or` exist only here, PHP reserves those function names):
 *
 *   ['age' => Operator::moreThan(18)]                       age > 18
 *   ['age' => Operator::and(Operator::moreThan(18), Operator::lessThan(65))]
 *   ['role' => Operator::or(Operator::equal('admin'), Operator::isNull())]
 *   ['createdAt' => Operator::raw(fn (string $column) => "$column > :since", ['since' => '2024-01-01'])]
 *
 * PostgreSQL only: arrayContains / arrayContainedBy / arrayOverlap (array columns: @> <@ &&) and
 * any (= ANY(...), an IN list elsewhere). jsonContains: PostgreSQL (jsonb @>) and MySQL/MariaDB (JSON_CONTAINS).
 */
final class Operator
{
    /** NOT the operand: a value (col != v, null → IS NOT NULL) or another operator (NOT(...)). */
    public static function not(mixed $value): FindOperator
    {
        return new FindOperator('not', $value);
    }

    public static function lessThan(mixed $value): FindOperator
    {
        return new FindOperator('lessThan', $value);
    }

    public static function lessThanOrEqual(mixed $value): FindOperator
    {
        return new FindOperator('lessThanOrEqual', $value);
    }

    public static function moreThan(mixed $value): FindOperator
    {
        return new FindOperator('moreThan', $value);
    }

    public static function moreThanOrEqual(mixed $value): FindOperator
    {
        return new FindOperator('moreThanOrEqual', $value);
    }

    /** col = value (use it for values a plain array key would read as IN, e.g. array or JSON columns). */
    public static function equal(mixed $value): FindOperator
    {
        return new FindOperator('equal', $value);
    }

    public static function like(string $pattern): FindOperator
    {
        return new FindOperator('like', $pattern);
    }

    /** Case-insensitive LIKE (ILIKE on PostgreSQL, LOWER() LIKE LOWER() elsewhere). */
    public static function iLike(string $pattern): FindOperator
    {
        return new FindOperator('ilike', $pattern);
    }

    public static function between(mixed $from, mixed $to): FindOperator
    {
        return new FindOperator('between', [$from, $to]);
    }

    /** @param iterable<mixed> $values */
    public static function in(iterable $values): FindOperator
    {
        return new FindOperator('in', is_array($values) ? array_values($values) : iterator_to_array($values, false));
    }

    /** PostgreSQL `col = ANY(array)`; an IN list on the other databases. @param iterable<mixed> $values */
    public static function any(iterable $values): FindOperator
    {
        return new FindOperator('any', is_array($values) ? array_values($values) : iterator_to_array($values, false));
    }

    public static function isNull(): FindOperator
    {
        return new FindOperator('isNull');
    }

    /**
     * Raw SQL for the column: a string is the right-hand side of `column = <sql>` (TypeORM), a closure receives
     * the quoted column reference and returns the whole condition. `:name` placeholders are bound from
     * $parameters (`:...name` expands a list).
     *
     *   ['likes' => Raw('dislikes - 4')]                                     likes = dislikes - 4
     *   ['title' => Raw(fn ($c) => "LOWER($c) = :t", ['t' => 'hello'])]
     *
     * @param array<string, mixed> $parameters
     */
    public static function raw(string|Closure $sql, array $parameters = []): FindOperator
    {
        return new FindOperator('raw', $sql, $parameters);
    }

    /** Every operand holds (TypeORM's And()). */
    public static function and(FindOperator|string|int|float|bool ...$operators): FindOperator
    {
        return new FindOperator('and', $operators);
    }

    /** Any operand holds (TypeORM's Or()). */
    public static function or(FindOperator|string|int|float|bool|null ...$operators): FindOperator
    {
        return new FindOperator('or', $operators);
    }

    /** Array column contains every value (PostgreSQL @>). @param list<mixed> $values */
    public static function arrayContains(array $values): FindOperator
    {
        return new FindOperator('arrayContains', array_values($values));
    }

    /** Array column is contained in the values (PostgreSQL <@). @param list<mixed> $values */
    public static function arrayContainedBy(array $values): FindOperator
    {
        return new FindOperator('arrayContainedBy', array_values($values));
    }

    /** Array column shares a value with the values (PostgreSQL &&). @param list<mixed> $values */
    public static function arrayOverlap(array $values): FindOperator
    {
        return new FindOperator('arrayOverlap', array_values($values));
    }

    /** JSON column contains the document (PostgreSQL jsonb @>, MySQL/MariaDB JSON_CONTAINS). */
    public static function jsonContains(mixed $document): FindOperator
    {
        return new FindOperator('jsonContains', $document);
    }
}
