<?php

declare(strict_types=1);

namespace Nesto\Tests\Prisma\Drivers;

final class PrismaSqliteTest extends PrismaDriverTestCase
{
    protected static function databaseUrl(): ?string
    {
        return 'file:./dev.db';
    }

    protected static function provider(): string
    {
        return 'sqlite';
    }
}
