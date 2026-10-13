<?php

declare(strict_types=1);

namespace Nesto\Cache\Attributes;

use Attribute;

/**
 * Opt a handler (or a whole controller) out of CacheInterceptor.
 *
 *   #[Get('me')] #[NoCache]
 *   public function me(): array {}
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class NoCache
{
}
