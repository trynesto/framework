<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures;

use Nesto\Common\Attributes\Module;
use Nesto\Orm\OrmModule;

#[Module(imports: [new OrmModule(type: 'sqlite', database: ':memory:', synchronize: true, entities: [Cat::class]), CatsModule::class])]
final class AppModule {}
