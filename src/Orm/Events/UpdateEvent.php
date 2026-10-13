<?php

declare(strict_types=1);

namespace Nesto\Orm\Events;

use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\EntityMetadata;
use Nesto\Orm\Mapping\ColumnMetadata;
use Nesto\Orm\Mapping\RelationMetadata;
use Nesto\Orm\QueryRunner;

/**
 * beforeUpdate / afterUpdate. From save(): `entity` is the entity (changes made in beforeUpdate are written),
 * `databaseEntity` a detached copy built from the row as it was loaded / last saved, `updatedColumns` the
 * changed columns and `updatedRelations` the owning relations whose foreign key changed. From update() /
 * query-builder updates: `entity` is the SET values array and databaseEntity is null.
 */
final class UpdateEvent extends OrmEvent
{
    /**
     * @param object|array<string, mixed>|null $entity
     * @param list<ColumnMetadata>             $updatedColumns
     * @param list<RelationMetadata>           $updatedRelations
     */
    public function __construct(
        DataSource $connection,
        QueryRunner $queryRunner,
        EntityManager $manager,
        public readonly object|array|null $entity,
        public readonly EntityMetadata $metadata,
        public readonly ?object $databaseEntity = null,
        public readonly array $updatedColumns = [],
        public readonly array $updatedRelations = [],
    ) {
        parent::__construct($connection, $queryRunner, $manager);
    }
}
