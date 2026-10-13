<?php

declare(strict_types=1);

namespace Nesto\Orm\Events;

use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\QueryRunner;

/**
 * What every subscriber event carries (TypeORM's event objects): the DataSource (`connection`, as in TypeORM),
 * the QueryRunner running the operation and its EntityManager — inside a transaction that is the
 * transactional manager, so further writes through `$event->manager` join the transaction.
 */
abstract class OrmEvent
{
    public function __construct(
        public readonly DataSource $connection,
        public readonly QueryRunner $queryRunner,
        public readonly EntityManager $manager,
    ) {}
}
