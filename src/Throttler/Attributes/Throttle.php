<?php

declare(strict_types=1);

namespace Nesto\Throttler\Attributes;

use Attribute;

/**
 * Override the module's rate limit for a controller or a single handler (Nest's @Throttle()).
 * Method-level wins over class-level; a null member falls back to the module option.
 *
 *   #[Throttle(limit: 3, ttl: 60)]      // 3 requests per 60 seconds
 *   #[Get('login')]
 *   public function login(): array {}
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class Throttle
{
    public function __construct(
        public readonly ?int $limit = null,
        public readonly ?int $ttl = null,
    ) {
    }
}
