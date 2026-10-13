<?php

declare(strict_types=1);

namespace Nesto\Orm;

/** TypeORM's TransactionNotStartedError: commitTransaction() / rollbackTransaction() without startTransaction(). */
class TransactionNotStartedException extends OrmException
{
    public function __construct()
    {
        parent::__construct('Transaction is not started yet, start transaction before committing or rolling it back.');
    }
}
