<?php

declare(strict_types=1);

namespace Nesto\Orm\Events;

/** beforeTransactionStart / afterTransactionStart (also for savepoints of nested transactions). */
final class TransactionStartEvent extends OrmEvent {}
