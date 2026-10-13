<?php

declare(strict_types=1);

namespace Nesto\Orm;

/** TypeORM's NoVersionOrUpdateDateColumnError: an optimistic lock needs a #[VersionColumn] or #[UpdateDateColumn]. */
class NoVersionOrUpdateDateColumnException extends OrmException
{
    public function __construct(string $entity)
    {
        parent::__construct('Entity ' . Mapping\Naming::shortName($entity) . ' does not have a version or update date column (needed for an optimistic lock).');
    }
}
