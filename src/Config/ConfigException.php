<?php

declare(strict_types=1);

namespace Nesto\Config;

/** Thrown by ConfigService::getOrThrow() and for invalid ConfigModule options. */
class ConfigException extends \RuntimeException {}
