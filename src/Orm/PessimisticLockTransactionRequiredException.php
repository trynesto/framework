<?php

declare(strict_types=1);

namespace Nesto\Orm;

/** TypeORM's PessimisticLockTransactionRequiredError: row locks (FOR UPDATE / FOR SHARE …) need an active transaction. */
class PessimisticLockTransactionRequiredException extends OrmException
{
    public function __construct()
    {
        parent::__construct('An open transaction is required for a pessimistic lock: run the query inside $dataSource->transaction() or a QueryRunner transaction.');
    }
}
