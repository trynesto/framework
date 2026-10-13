<?php

declare(strict_types=1);

namespace Nesto\Tests\Prisma\Fixtures\App;

use Nesto\Common\Attributes\Module;
use Nesto\Prisma\PrismaModule;

#[Module(imports: [new PrismaModule(), UsersModule::class])]
final class AppModule
{
}
