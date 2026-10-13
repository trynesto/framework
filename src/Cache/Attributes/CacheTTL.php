<?php

declare(strict_types=1);

namespace Nesto\Cache\Attributes;

use Attribute;

/**
 * Per-handler (or per-controller) TTL in seconds for CacheInterceptor (Nest's @CacheTTL()).
 * `0` keeps the entry until the store evicts it.
 *
 *   #[CacheTTL(60)]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class CacheTTL
{
    public function __construct(public readonly int $ttl)
    {
    }
}
