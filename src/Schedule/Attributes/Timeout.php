<?php

declare(strict_types=1);

namespace Nesto\Schedule\Attributes;

use Attribute;

/**
 * Run a provider method once, N milliseconds after the scheduler starts (Nest's @Timeout()).
 *
 *   #[Timeout(1000, name: 'warmup')]
 *   public function warmup(): void {}
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Timeout
{
    public function __construct(
        public readonly int $milliseconds,
        public readonly ?string $name = null,
    ) {
    }
}
