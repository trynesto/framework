<?php

declare(strict_types=1);

namespace Nesto\Cache\Attributes;

use Attribute;

/**
 * Fixed cache key for a handler guarded by CacheInterceptor (Nest's @CacheKey()).
 *
 *   #[Get('cats')] #[CacheKey('cats.all')] #[CacheTTL(30)]
 *   public function findAll(): array {}
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class CacheKey
{
    public function __construct(public readonly string $key)
    {
    }
}
