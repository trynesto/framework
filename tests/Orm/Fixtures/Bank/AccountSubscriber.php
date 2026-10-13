<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Bank;

use Nesto\Orm\Attributes\EventSubscriber;
use Nesto\Orm\Events\EntitySubscriberInterface;
use Nesto\Orm\Events\InsertEvent;
use Nesto\Tests\Orm\Fixtures\Events\Account;

/** A provider marked #[EventSubscriber]: created with DI and registered on the default DataSource. */
#[EventSubscriber]
final class AccountSubscriber implements EntitySubscriberInterface
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function listenTo(): string
    {
        return Account::class;
    }

    public function afterInsert(InsertEvent $event): void
    {
        $this->audit->lines[] = 'inserted ' . $event->entity->owner;
    }
}
