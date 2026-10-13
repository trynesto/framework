<?php

declare(strict_types=1);

namespace Nesto\Throttler\Attributes;

use Attribute;

/**
 * Exempt a controller or handler from ThrottlerGuard (Nest's @SkipThrottle()).
 * `#[SkipThrottle(false)]` on a method re-enables throttling inside a skipped controller.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class SkipThrottle
{
    public function __construct(public readonly bool $skip = true)
    {
    }
}
