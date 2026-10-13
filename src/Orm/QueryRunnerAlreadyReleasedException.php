<?php

declare(strict_types=1);

namespace Nesto\Orm;

/** TypeORM's QueryRunnerAlreadyReleasedError: the QueryRunner was released and cannot be used any more. */
class QueryRunnerAlreadyReleasedException extends OrmException
{
    public function __construct()
    {
        parent::__construct('Query runner already released. Cannot run queries anymore.');
    }
}
