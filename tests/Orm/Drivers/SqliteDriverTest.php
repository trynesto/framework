<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers;

/** Driver integration suite on Sqlite. See DriverTestCase. */
final class SqliteDriverTest extends DriverTestCase
{
    protected static function options(): ?array
    {
        return ['type' => 'sqlite', 'database' => ':memory:'];
    }

    protected static function platform(): string
    {
        return 'sqlite';
    }
}
