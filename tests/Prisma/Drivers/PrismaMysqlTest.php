<?php

declare(strict_types=1);

namespace Nesto\Tests\Prisma\Drivers;

final class PrismaMysqlTest extends PrismaDriverTestCase
{
    protected static function databaseUrl(): ?string
    {
        return self::urlFromEnv('NESTO_TEST_MYSQL_URL');
    }

    protected static function provider(): string
    {
        return 'mysql';
    }
}
