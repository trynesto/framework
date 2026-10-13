<?php

declare(strict_types=1);

namespace Nesto\Tests\Swagger\Fixtures;

use Nesto\Common\Attributes\Module;
use Nesto\Tests\Auth\Fixtures\AppModule as AuthAppModule;

#[Module(imports: [AuthAppModule::class], controllers: [UsersController::class, InternalController::class])]
final class SwaggerAppModule {}
