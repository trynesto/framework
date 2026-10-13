<?php

declare(strict_types=1);

namespace Nesto\Orm\Events;

/**
 * TypeORM's EntitySubscriberInterface. Every method is optional (like in TypeORM): implement only the ones you
 * need, with these signatures:
 *
 *   listenTo(): string                          entity class (subclasses included); omit to get every entity
 *   afterLoad(object $entity, LoadEvent $event): void
 *   beforeInsert(InsertEvent $event): void       afterInsert(InsertEvent $event): void
 *   beforeUpdate(UpdateEvent $event): void       afterUpdate(UpdateEvent $event): void
 *   beforeRemove(RemoveEvent $event): void       afterRemove(RemoveEvent $event): void
 *   beforeSoftRemove(SoftRemoveEvent $event): void   afterSoftRemove(SoftRemoveEvent $event): void
 *   beforeRecover(RecoverEvent $event): void     afterRecover(RecoverEvent $event): void
 *   beforeTransactionStart(TransactionStartEvent $event): void      afterTransactionStart(...)
 *   beforeTransactionCommit(TransactionCommitEvent $event): void    afterTransactionCommit(...)
 *   beforeTransactionRollback(TransactionRollbackEvent $event): void afterTransactionRollback(...)
 *   beforeQuery(BeforeQueryEvent $event): void   afterQuery(AfterQueryEvent $event): void
 *
 * Register it with the `subscribers` option (DataSource / OrmModule), `$dataSource->subscribers[] = $this`
 * (the @nestjs/typeorm pattern, in a provider's constructor), or as a provider marked #[EventSubscriber].
 * Entity events fire for save() / remove() / softRemove() / recover() / upsert() and for query-builder
 * insert / update / delete (there `entity` is the values array, or null for delete) and Repository::insert().
 */
interface EntitySubscriberInterface {}
