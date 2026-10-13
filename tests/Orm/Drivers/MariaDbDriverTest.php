<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers;

/** Driver integration suite on MariaDb (skipped unless NESTO_TEST_MARIADB_URL is set). See DriverTestCase. */
final class MariaDbDriverTest extends DriverTestCase
{
    protected static function options(): ?array
    {
        return self::optionsFromEnv('NESTO_TEST_MARIADB_URL');
    }

    protected static function platform(): string
    {
        return 'mariadb';
    }

    protected static function envVar(): string
    {
        return 'NESTO_TEST_MARIADB_URL';
    }
}
