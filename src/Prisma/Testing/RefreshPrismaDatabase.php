<?php

declare(strict_types=1);

namespace Nesto\Prisma\Testing;

use Nesto\Prisma\Client\PrismaClient;

/**
 * PHPUnit helper: points the client at the test database, pushes the schema there once per process and empties
 * every table before each test. Only the database of $url is touched; a database whose name does not contain
 * "test" is refused unless $force is true.
 *
 *   final class UsersTest extends TestCase
 *   {
 *       use RefreshPrismaDatabase;
 *
 *       protected function setUp(): void
 *       {
 *           $this->app = NestoFactory::create(AppModule::class);
 *           $this->app->init();                                      // PrismaService connects to DATABASE_URL (.env)
 *           $this->prisma = $this->app->get(PrismaService::class);
 *           // moves the service to the test database, then resets that one — never the database of .env
 *           $this->refreshPrismaDatabase($this->prisma, base_path('prisma/schema.prisma'), getenv('TEST_DATABASE_URL'));
 *       }
 *   }
 *
 * Without an app: `new PrismaClient(['datasourceUrl' => getenv('TEST_DATABASE_URL')])` (or the generated client).
 */
trait RefreshPrismaDatabase
{
    protected function refreshPrismaDatabase(PrismaClient $prisma, ?string $schemaPath = null, ?string $url = null, bool $force = false): void
    {
        PrismaTestDatabase::refresh($prisma, $schemaPath, $url, $force);
    }
}
