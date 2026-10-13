<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Bank;

use Nesto\Common\Attributes\Module;
use Nesto\Orm\OrmModule;
use Nesto\Tests\Orm\Fixtures\Events\Account;
use Nesto\Tests\Orm\Fixtures\Events\AccountEntry;
use Nesto\Tests\Orm\Fixtures\Tag;

#[Module(imports: [
    new OrmModule(type: 'sqlite', database: ':memory:', synchronize: true, entities: [Account::class, AccountEntry::class], subscribers: [OptionSubscriber::class]),
    new OrmModule(name: 'analytics', type: 'sqlite', database: ':memory:', synchronize: true, entities: [Tag::class]),
    BankModule::class,
])]
final class BankAppModule {}
