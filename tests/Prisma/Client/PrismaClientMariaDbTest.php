<?php

declare(strict_types=1);

namespace Nesto\Tests\Prisma\Client;

final class PrismaClientMariaDbTest extends PrismaClientTestCase
{
    protected static function databaseUrl(): ?string
    {
        return self::urlFromEnv('NESTO_TEST_MARIADB_URL');
    }

    protected static function provider(): string
    {
        return 'mysql';
    }
}
