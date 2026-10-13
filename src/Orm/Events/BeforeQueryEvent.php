<?php

declare(strict_types=1);

namespace Nesto\Orm\Events;

use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\QueryRunner;

/** beforeQuery: a statement is about to run (not fired for statements a subscriber runs from a query event). */
final class BeforeQueryEvent extends OrmEvent
{
    /** @param array<string|int, mixed> $parameters */
    public function __construct(
        DataSource $connection,
        QueryRunner $queryRunner,
        EntityManager $manager,
        public readonly string $query,
        public readonly array $parameters,
    ) {
        parent::__construct($connection, $queryRunner, $manager);
    }
}
