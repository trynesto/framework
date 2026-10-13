<?php

declare(strict_types=1);

namespace Nesto\Orm\Events;

use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\EntityMetadata;
use Nesto\Orm\QueryRunner;

/**
 * beforeInsert / afterInsert. `entity` is the entity being saved (changes made in beforeInsert are written);
 * for Repository::insert() / query-builder inserts it is the inserted values array.
 */
final class InsertEvent extends OrmEvent
{
    /** @param object|array<string, mixed> $entity */
    public function __construct(
        DataSource $connection,
        QueryRunner $queryRunner,
        EntityManager $manager,
        public readonly object|array $entity,
        public readonly EntityMetadata $metadata,
    ) {
        parent::__construct($connection, $queryRunner, $manager);
    }
}
