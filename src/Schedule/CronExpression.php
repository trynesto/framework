<?php

declare(strict_types=1);

namespace Nesto\Schedule;

/**
 * Ready-made cron expressions (same names as Nest's CronExpression enum).
 *
 *   #[Cron(CronExpression::EVERY_5_MINUTES)]
 */
final class CronExpression
{
    public const EVERY_SECOND = '* * * * * *';
    public const EVERY_5_SECONDS = '*/5 * * * * *';
    public const EVERY_10_SECONDS = '*/10 * * * * *';
    public const EVERY_30_SECONDS = '*/30 * * * * *';
    public const EVERY_MINUTE = '* * * * *';
    public const EVERY_5_MINUTES = '*/5 * * * *';
    public const EVERY_10_MINUTES = '*/10 * * * *';
    public const EVERY_30_MINUTES = '*/30 * * * *';
    public const EVERY_HOUR = '0 * * * *';
    public const EVERY_2_HOURS = '0 */2 * * *';
    public const EVERY_6_HOURS = '0 */6 * * *';
    public const EVERY_12_HOURS = '0 */12 * * *';
    public const EVERY_DAY_AT_MIDNIGHT = '0 0 * * *';
    public const EVERY_DAY_AT_NOON = '0 12 * * *';
    public const EVERY_DAY_AT_1AM = '0 1 * * *';
    public const EVERY_DAY_AT_6AM = '0 6 * * *';
    public const EVERY_WEEKDAY = '0 0 * * 1-5';
    public const EVERY_WEEKEND = '0 0 * * 6,0';
    public const EVERY_WEEK = '0 0 * * 0';
    public const EVERY_1ST_DAY_OF_MONTH_AT_MIDNIGHT = '0 0 1 * *';
    public const EVERY_1ST_DAY_OF_MONTH_AT_NOON = '0 12 1 * *';
    public const EVERY_QUARTER = '0 0 1 */3 *';
    public const EVERY_YEAR = '0 0 1 1 *';
    public const MONDAY_TO_FRIDAY_AT_9AM = '0 9 * * 1-5';
    public const MONDAY_TO_FRIDAY_AT_6PM = '0 18 * * 1-5';

    private function __construct()
    {
    }
}
