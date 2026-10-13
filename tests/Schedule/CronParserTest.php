<?php

declare(strict_types=1);

namespace Nesto\Tests\Schedule;

use DateTimeImmutable;
use Nesto\Schedule\CronExpression;
use Nesto\Schedule\CronParser;
use Nesto\Schedule\InvalidCronExpressionException;
use PHPUnit\Framework\TestCase;

final class CronParserTest extends TestCase
{
    private CronParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CronParser;
    }

    public function testIsDue(): void
    {
        $at = fn (string $s) => new DateTimeImmutable($s);

        self::assertTrue($this->parser->isDue(CronExpression::EVERY_MINUTE, $at('2026-10-12 10:07:00')));
        self::assertTrue($this->parser->isDue('*/5 * * * *', $at('2026-10-12 10:05:00')));
        self::assertFalse($this->parser->isDue('*/5 * * * *', $at('2026-10-12 10:06:00')));
        self::assertTrue($this->parser->isDue('0 9 * * MON-FRI', $at('2026-10-12 09:00:00')));   // Monday
        self::assertFalse($this->parser->isDue('0 9 * * MON-FRI', $at('2026-10-11 09:00:00')));  // Sunday
        self::assertTrue($this->parser->isDue('0 0 1 JAN,JUL *', $at('2026-07-01 00:00:00')));
        self::assertTrue($this->parser->isDue('@hourly', $at('2026-10-12 13:00:00')));
        self::assertFalse($this->parser->isDue('@daily', $at('2026-10-12 13:00:00')));
        self::assertTrue($this->parser->isDue('0 0 * * 7', $at('2026-10-11 00:00:00')));          // 7 == Sunday
        self::assertTrue($this->parser->isDue('30 * * * * *', $at('2026-10-12 10:00:30')));       // 6 fields
        self::assertFalse($this->parser->isDue('30 * * * * *', $at('2026-10-12 10:00:31')));
        self::assertTrue($this->parser->isDue('0 0 15 * MON', $at('2026-10-15 00:00:00')));       // dom OR dow (Thursday the 15th)
        self::assertTrue($this->parser->isDue('0 0 15 * MON', $at('2026-10-12 00:00:00')));       // Monday the 12th
        self::assertTrue($this->parser->isDue('1-3,10/20 * * * *', $at('2026-10-12 00:30:00')));
    }

    public function testNextRun(): void
    {
        $from = new DateTimeImmutable('2026-10-12 10:07:30');

        self::assertSame('2026-10-12 10:08:00', $this->parser->nextRun('* * * * *', $from)->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-12 10:10:00', $this->parser->nextRun('*/5 * * * *', $from)->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-13 00:00:00', $this->parser->nextRun('@daily', $from)->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-13 09:00:00', $this->parser->nextRun('0 9 * * MON-FRI', $from)->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-01 00:00:00', $this->parser->nextRun('@monthly', $from)->format('Y-m-d H:i:s'));
        self::assertSame('2027-01-01 00:00:00', $this->parser->nextRun('@yearly', $from)->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-12 10:07:35', $this->parser->nextRun('*/5 * * * * *', $from)->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-17 00:00:00', $this->parser->nextRun('0 0 * * SAT', $from)->format('Y-m-d H:i:s'));
        self::assertSame('2028-02-29 00:00:00', $this->parser->nextRun('0 0 29 2 *', $from)->format('Y-m-d H:i:s'));

        $runs = $this->parser->nextRuns('0 * * * *', 3, $from);
        self::assertSame(['2026-10-12 11:00:00', '2026-10-12 12:00:00', '2026-10-12 13:00:00'], array_map(fn ($d) => $d->format('Y-m-d H:i:s'), $runs));
    }

    public function testInvalidExpressions(): void
    {
        self::assertFalse($this->parser->isValid('* * *'));
        self::assertFalse($this->parser->isValid('60 * * * *'));
        self::assertFalse($this->parser->isValid('* * * 13 *'));
        self::assertFalse($this->parser->isValid('* * * * FOO'));
        self::assertTrue($this->parser->isValid('@weekly'));

        $this->expectException(InvalidCronExpressionException::class);
        $this->parser->parse('5-1 * * * *');
    }
}
