<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers;

/** Driver integration suite on Mysql (skipped unless NESTO_TEST_MYSQL_URL is set). See DriverTestCase. */
final class MysqlDriverTest extends DriverTestCase
{
    protected static function options(): ?array
    {
        return self::optionsFromEnv('NESTO_TEST_MYSQL_URL');
    }

    protected static function platform(): string
    {
        return 'mysql';
    }

    protected static function envVar(): string
    {
        return 'NESTO_TEST_MYSQL_URL';
    }
}
