<?php

declare(strict_types=1);

namespace Nesto\Tests\Prisma\Client;

final class PrismaClientSqliteTest extends PrismaClientTestCase
{
    protected static function databaseUrl(): ?string
    {
        return 'file:./test.db';
    }

    protected static function provider(): string
    {
        return 'sqlite';
    }
}
