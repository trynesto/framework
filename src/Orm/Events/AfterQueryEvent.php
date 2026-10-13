<?php

declare(strict_types=1);

namespace Nesto\Orm\Events;

use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\QueryRunner;

/**
 * afterQuery: a statement ran. `executionTime` is in seconds; `rawResults` is the (unconsumed) PDOStatement
 * on success, `error` the QueryException on failure.
 */
final class AfterQueryEvent extends OrmEvent
{
    /** @param array<string|int, mixed> $parameters */
    public function __construct(
        DataSource $connection,
        QueryRunner $queryRunner,
        EntityManager $manager,
        public readonly string $query,
        public readonly array $parameters,
        public readonly bool $success,
        public readonly float $executionTime,
        public readonly ?\PDOStatement $rawResults = null,
        public readonly ?\Throwable $error = null,
    ) {
        parent::__construct($connection, $queryRunner, $manager);
    }
}
