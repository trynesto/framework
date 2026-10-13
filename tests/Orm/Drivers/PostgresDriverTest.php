<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers;

/** Driver integration suite on Postgres (skipped unless NESTO_TEST_PGSQL_URL is set). See DriverTestCase. */
final class PostgresDriverTest extends DriverTestCase
{
    protected static function options(): ?array
    {
        return self::optionsFromEnv('NESTO_TEST_PGSQL_URL');
    }

    protected static function platform(): string
    {
        return 'pgsql';
    }

    protected static function envVar(): string
    {
        return 'NESTO_TEST_PGSQL_URL';
    }
}
