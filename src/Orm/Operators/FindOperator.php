<?php

declare(strict_types=1);

namespace Nesto\Orm\Operators;

use Closure;

/**
 * A condition on one column in a `where` array (TypeORM's FindOperator), built with the functions of this
 * namespace or the static methods of Operator:
 *
 *   use function Nesto\Orm\Operators\{MoreThan, In, Not, Like};
 *   $users->findBy(['age' => MoreThan(18), 'role' => In(['admin', 'editor']), 'name' => Not(Like('A%'))]);
 *   $users->findBy(['age' => Operator::and(MoreThan(18), LessThan(65))]);
 *
 * Values are converted like plain values (column type, value transformers, relation keys).
 */
final class FindOperator
{
    public const TYPES = [
        'not', 'lessThan', 'lessThanOrEqual', 'moreThan', 'moreThanOrEqual', 'equal', 'like', 'ilike', 'between', 'in',
        'any', 'isNull', 'raw', 'and', 'or', 'arrayContains', 'arrayContainedBy', 'arrayOverlap', 'jsonContains',
    ];

    /**
     * @param mixed $value  the operand: a value, a list (in, any, between, array*), a child FindOperator (not),
     *                      a list of FindOperators (and, or), or the SQL / closure of raw
     * @param array<string, mixed> $parameters named parameters of a raw condition
     */
    public function __construct(
        public readonly string $type,
        public readonly mixed $value = null,
        public readonly array $parameters = [],
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown find operator \"$type\".");
        }
        if ($type === 'raw' && ! is_string($value) && ! $value instanceof Closure) {
            throw new \InvalidArgumentException('Raw() takes an SQL string or a closure receiving the column reference.');
        }
    }

    /** Operands of and / or (plain values become Equal). @return list<self> */
    public function children(): array
    {
        return array_map(fn (mixed $v) => $v instanceof self ? $v : new self('equal', $v), array_values((array) $this->value));
    }
}
