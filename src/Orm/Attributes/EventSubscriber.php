<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Marks an EntitySubscriberInterface provider (TypeORM's @EventSubscriber): listed in a module's providers, it is
 * created with dependency injection and registered on the DataSource (the named one with `dataSource`).
 *
 *   #[EventSubscriber]
 *   final class UserSubscriber implements EntitySubscriberInterface {
 *       public function __construct(private readonly AuditLog $audit) {}
 *       public function listenTo(): string { return User::class; }
 *       public function afterInsert(InsertEvent $event): void { $this->audit->record($event->entity); }
 *   }
 *
 *   #[Module(providers: [UserSubscriber::class])] final class UsersModule {}
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class EventSubscriber
{
    public function __construct(public readonly ?string $dataSource = null) {}
}
