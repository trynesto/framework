<?php

declare(strict_types=1);

namespace Nesto\Cache;

/** PSR-16 invalid key / ttl error. */
final class InvalidArgumentException extends \InvalidArgumentException implements \Psr\SimpleCache\InvalidArgumentException
{
}
