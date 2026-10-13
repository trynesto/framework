<?php

declare(strict_types=1);

/**
 * TypeORM's find operators as functions (see Operator; And / Or are Operator::and() / Operator::or()):
 *
 *   use function Nesto\Orm\Operators\{MoreThan, In, Not, Like, IsNull};
 *   $repo->findBy(['age' => MoreThan(18), 'status' => In(['a', 'b']), 'deletedAt' => IsNull()]);
 */

namespace Nesto\Orm\Operators;

use Closure;

function Not(mixed $value): FindOperator
{
    return Operator::not($value);
}

function LessThan(mixed $value): FindOperator
{
    return Operator::lessThan($value);
}

function LessThanOrEqual(mixed $value): FindOperator
{
    return Operator::lessThanOrEqual($value);
}

function MoreThan(mixed $value): FindOperator
{
    return Operator::moreThan($value);
}

function MoreThanOrEqual(mixed $value): FindOperator
{
    return Operator::moreThanOrEqual($value);
}

function Equal(mixed $value): FindOperator
{
    return Operator::equal($value);
}

function Like(string $pattern): FindOperator
{
    return Operator::like($pattern);
}

function ILike(string $pattern): FindOperator
{
    return Operator::iLike($pattern);
}

function Between(mixed $from, mixed $to): FindOperator
{
    return Operator::between($from, $to);
}

/** @param iterable<mixed> $values */
function In(iterable $values): FindOperator
{
    return Operator::in($values);
}

/** @param iterable<mixed> $values */
function Any(iterable $values): FindOperator
{
    return Operator::any($values);
}

function IsNull(): FindOperator
{
    return Operator::isNull();
}

/** @param array<string, mixed> $parameters */
function Raw(string|Closure $sql, array $parameters = []): FindOperator
{
    return Operator::raw($sql, $parameters);
}

/** @param list<mixed> $values */
function ArrayContains(array $values): FindOperator
{
    return Operator::arrayContains($values);
}

/** @param list<mixed> $values */
function ArrayContainedBy(array $values): FindOperator
{
    return Operator::arrayContainedBy($values);
}

/** @param list<mixed> $values */
function ArrayOverlap(array $values): FindOperator
{
    return Operator::arrayOverlap($values);
}

function JsonContains(mixed $document): FindOperator
{
    return Operator::jsonContains($document);
}
