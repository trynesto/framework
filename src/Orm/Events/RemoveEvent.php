<?php

declare(strict_types=1);

namespace Nesto\Orm\Events;

use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\EntityMetadata;
use Nesto\Orm\QueryRunner;

/**
 * beforeRemove / afterRemove (and, as SoftRemoveEvent / RecoverEvent, the soft-remove and recover events).
 * `entityId` is the primary key; `entity` is null for delete() / query-builder deletes.
 */
class RemoveEvent extends OrmEvent
{
    public function __construct(
        DataSource $connection,
        QueryRunner $queryRunner,
        EntityManager $manager,
        public readonly ?object $entity,
        public readonly EntityMetadata $metadata,
        public readonly ?object $databaseEntity = null,
        public readonly mixed $entityId = null,
    ) {
        parent::__construct($connection, $queryRunner, $manager);
    }
}
