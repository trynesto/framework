<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Events;

use Nesto\Orm\Events\AfterQueryEvent;
use Nesto\Orm\Events\BeforeQueryEvent;
use Nesto\Orm\Events\EntitySubscriberInterface;
use Nesto\Orm\Events\InsertEvent;
use Nesto\Orm\Events\LoadEvent;
use Nesto\Orm\Events\RecoverEvent;
use Nesto\Orm\Events\RemoveEvent;
use Nesto\Orm\Events\SoftRemoveEvent;
use Nesto\Orm\Events\TransactionCommitEvent;
use Nesto\Orm\Events\TransactionRollbackEvent;
use Nesto\Orm\Events\TransactionStartEvent;
use Nesto\Orm\Events\UpdateEvent;

/** Records every event it receives (method => list of events). */
final class RecordingSubscriber implements EntitySubscriberInterface
{
    /** @var list<array{0: string, 1: object}> */
    public array $events = [];
    public bool $queries = false;

    public function __construct(private readonly ?string $listenTo = Account::class) {}

    public function listenTo(): ?string
    {
        return $this->listenTo;
    }

    /** @return list<object> events received by $method */
    public function of(string $method): array
    {
        return array_values(array_map(fn (array $e) => $e[1], array_filter($this->events, fn (array $e) => $e[0] === $method)));
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(fn (array $e) => $e[0], $this->events);
    }

    public function afterLoad(object $entity, LoadEvent $event): void
    {
        $this->events[] = ['afterLoad', $event];
        EventLog::add('subscriber afterLoad ' . $entity::class);
    }

    public function beforeInsert(InsertEvent $event): void
    {
        $this->events[] = ['beforeInsert', $event];
        EventLog::add('subscriber beforeInsert');
    }

    public function afterInsert(InsertEvent $event): void
    {
        $this->events[] = ['afterInsert', $event];
    }

    public function beforeUpdate(UpdateEvent $event): void
    {
        $this->events[] = ['beforeUpdate', $event];
        EventLog::add('subscriber beforeUpdate');
    }

    public function afterUpdate(UpdateEvent $event): void
    {
        $this->events[] = ['afterUpdate', $event];
    }

    public function beforeRemove(RemoveEvent $event): void
    {
        $this->events[] = ['beforeRemove', $event];
    }

    public function afterRemove(RemoveEvent $event): void
    {
        $this->events[] = ['afterRemove', $event];
    }

    public function beforeSoftRemove(SoftRemoveEvent $event): void
    {
        $this->events[] = ['beforeSoftRemove', $event];
    }

    public function afterSoftRemove(SoftRemoveEvent $event): void
    {
        $this->events[] = ['afterSoftRemove', $event];
    }

    public function beforeRecover(RecoverEvent $event): void
    {
        $this->events[] = ['beforeRecover', $event];
    }

    public function afterRecover(RecoverEvent $event): void
    {
        $this->events[] = ['afterRecover', $event];
    }

    public function beforeTransactionStart(TransactionStartEvent $event): void
    {
        $this->events[] = ['beforeTransactionStart', $event];
    }

    public function afterTransactionStart(TransactionStartEvent $event): void
    {
        $this->events[] = ['afterTransactionStart', $event];
    }

    public function beforeTransactionCommit(TransactionCommitEvent $event): void
    {
        $this->events[] = ['beforeTransactionCommit', $event];
    }

    public function afterTransactionCommit(TransactionCommitEvent $event): void
    {
        $this->events[] = ['afterTransactionCommit', $event];
    }

    public function beforeTransactionRollback(TransactionRollbackEvent $event): void
    {
        $this->events[] = ['beforeTransactionRollback', $event];
    }

    public function afterTransactionRollback(TransactionRollbackEvent $event): void
    {
        $this->events[] = ['afterTransactionRollback', $event];
    }

    public function beforeQuery(BeforeQueryEvent $event): void
    {
        if ($this->queries) {
            $this->events[] = ['beforeQuery', $event];
        }
    }

    public function afterQuery(AfterQueryEvent $event): void
    {
        if ($this->queries) {
            $this->events[] = ['afterQuery', $event];
        }
    }
}
