<?php

declare(strict_types=1);

namespace Nesto\Tests\Prisma\Fixtures\App;

use Nesto\Common\Attributes\Module;

/** Does not import PrismaModule: it is global. */
#[Module(controllers: [UsersController::class], providers: [UsersService::class])]
final class UsersModule
{
}
