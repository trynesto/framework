<?php

declare(strict_types=1);

namespace Nesto\Tests\Prisma\Drivers;

/** MariaDB runs on Prisma's mysql provider (`provider = "mysql"`, a mysql:// or mariadb:// URL). */
final class PrismaMariaDbTest extends PrismaDriverTestCase
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
