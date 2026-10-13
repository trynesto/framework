<?php

declare(strict_types=1);

namespace Nesto\Cache;

/** A cache store cannot be built or reached (missing Redis client, no database connection…). */
class CacheException extends \RuntimeException implements \Psr\SimpleCache\CacheException
{
}
