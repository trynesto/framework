<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Events;

/** Records listener / subscriber calls in order (tests reset it in setUp). */
final class EventLog
{
    /** @var list<string> */
    public static array $entries = [];

    public static function add(string $entry): void
    {
        self::$entries[] = $entry;
    }

    /** @return list<string> */
    public static function take(): array
    {
        $entries = self::$entries;
        self::$entries = [];

        return $entries;
    }
}
