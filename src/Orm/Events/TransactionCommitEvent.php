<?php

declare(strict_types=1);

namespace Nesto\Orm\Events;

/** beforeTransactionCommit / afterTransactionCommit (also for released savepoints). */
final class TransactionCommitEvent extends OrmEvent {}
