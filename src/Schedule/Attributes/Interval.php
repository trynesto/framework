<?php

declare(strict_types=1);

namespace Nesto\Schedule\Attributes;

use Attribute;

/**
 * Run a provider method every N milliseconds while the scheduler is running (Nest's @Interval()).
 *
 *   #[Interval(5000, name: 'heartbeat')]
 *   public function heartbeat(): void {}
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Interval
{
    public function __construct(
        public readonly int $milliseconds,
        public readonly ?string $name = null,
    ) {
    }
}
