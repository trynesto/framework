<?php

declare(strict_types=1);

namespace Nesto\Orm;

/** TypeORM's OptimisticLockCanNotBeUsedError: an optimistic lock only works with findOne() / getOne(). */
class OptimisticLockCanNotBeUsedException extends OrmException
{
    public function __construct()
    {
        parent::__construct('The optimistic lock can be used only with getOne() / findOne() methods.');
    }
}
