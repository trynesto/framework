<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures;

use Nesto\Common\Attributes\Module;
use Nesto\Orm\OrmFeatureModule;

#[Module(imports: [new OrmFeatureModule(Cat::class)], controllers: [CatsController::class], providers: [CatsService::class])]
final class CatsModule {}
