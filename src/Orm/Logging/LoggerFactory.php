<?php

declare(strict_types=1);

namespace Nesto\Orm\Logging;

use Nesto\Orm\OrmException;
use Psr\Log\LoggerInterface;

/**
 * Builds the DataSource's logger from the `logger` and `logging` options (TypeORM's LoggerFactory):
 *
 *   'logger'  => 'advanced-console' (default) | 'simple-console' | 'file' | 'debug'
 *                | a Logger instance | a PSR-3 LoggerInterface (wrapped in PsrLogger)
 *   'logging' => true (queries + errors, as TypeORM) | 'all' | false | ['query', 'error', 'schema', 'warn', 'info', 'log', 'migration']
 *   'maxQueryExecutionTime' => 500     // ms: slower queries are logged as slow (whatever `logging` says)
 *
 * Unlike TypeORM, nothing is written when none of the three options is set (a NullLogger), so a plain
 * DataSource stays silent on STDERR.
 */
final class LoggerFactory
{
    /** @param array<string, mixed> $options DataSource options */
    public static function fromOptions(array $options): Logger
    {
        $logging = $options['logging'] ?? false;
        if ($logging instanceof \Closure) {
            $logging = false; // the legacy per-statement callback is installed separately
        }
        $logger = $options['logger'] ?? null;
        if ($logger instanceof \Closure) {
            $logger = null; // legacy: a callback receiving warnings
        }
        $configured = $logger !== null || ($logging !== false && $logging !== [] && $logging !== null) || isset($options['maxQueryExecutionTime']);
        if (! $configured) {
            return new NullLogger;
        }

        return self::create($logger ?? 'advanced-console', $logging === null ? false : $logging);
    }

    /** @param bool|string|list<string> $logging */
    public static function create(string|Logger|LoggerInterface $logger, bool|string|array $logging = false): Logger
    {
        if ($logger instanceof Logger) {
            return $logger;
        }
        if ($logger instanceof LoggerInterface) {
            return new PsrLogger($logger, $logging);
        }

        return match ($logger) {
            'advanced-console' => new AdvancedConsoleLogger($logging),
            'simple-console' => new SimpleConsoleLogger($logging),
            'file' => new FileLogger($logging),
            'debug' => new DebugLogger,
            default => throw new OrmException("Unknown logger \"$logger\" (expected advanced-console, simple-console, file, debug, a Nesto\\Orm\\Logging\\Logger or a PSR-3 logger)."),
        };
    }
}
