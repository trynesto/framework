<?php

declare(strict_types=1);

namespace Nesto\Tests\Auth\Fixtures;

use Nesto\Common\Attributes\Module;

#[Module(
    controllers: [AuthController::class],
    providers: [UsersService::class, AppJwtStrategy::class],
    exports: [UsersService::class],
)]
final class UsersModule {}
