<?php

declare(strict_types=1);

namespace Nesto\Orm\Events;

/** beforeTransactionRollback / afterTransactionRollback (also for savepoints rolled back). */
final class TransactionRollbackEvent extends OrmEvent {}
