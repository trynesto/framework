<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Bank;

use Nesto\Common\Attributes\Module;
use Nesto\Orm\OrmFeatureModule;
use Nesto\Tests\Orm\Fixtures\Events\AccountEntry;
use Nesto\Tests\Orm\Fixtures\Events\AccountRepository;
use Nesto\Tests\Orm\Fixtures\Tag;

#[Module(
    imports: [new OrmFeatureModule(AccountRepository::class, AccountEntry::class), new OrmFeatureModule(Tag::class, dataSource: 'analytics')],
    controllers: [BankController::class],
    providers: [BankService::class, AuditTrail::class, AccountSubscriber::class, NestStyleSubscriber::class],
    exports: [AuditTrail::class],
)]
final class BankModule {}
