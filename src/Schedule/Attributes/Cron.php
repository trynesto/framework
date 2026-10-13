<?php

declare(strict_types=1);

namespace Nesto\Schedule\Attributes;

use Attribute;

/**
 * Run a provider method on a cron schedule (Nest's @Cron()).
 *
 *   #[Cron('* /5 * * * *')]                                  // every 5 minutes (5-field)
 *   #[Cron('30 * * * * *')]                                   // every minute at second 30 (6-field, seconds first)
 *   #[Cron(CronExpression::EVERY_DAY_AT_MIDNIGHT, name: 'cleanup', timeZone: 'Europe/Paris')]
 *   public function cleanup(): void {}
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Cron
{
    public function __construct(
        public readonly string $expression,
        public readonly ?string $name = null,
        public readonly ?string $timeZone = null,
    ) {
    }
}
