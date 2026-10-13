<?php

declare(strict_types=1);

namespace Nesto\Orm\Events;

use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\EntityMetadata;
use Nesto\Orm\QueryRunner;

/** afterLoad(object $entity, LoadEvent $event). */
final class LoadEvent extends OrmEvent
{
    public function __construct(
        DataSource $connection,
        QueryRunner $queryRunner,
        EntityManager $manager,
        public readonly object $entity,
        public readonly EntityMetadata $metadata,
    ) {
        parent::__construct($connection, $queryRunner, $manager);
    }
}
