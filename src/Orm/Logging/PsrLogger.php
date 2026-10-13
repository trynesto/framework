<?php

declare(strict_types=1);

namespace Nesto\Orm\Logging;

use Nesto\Orm\QueryRunner;
use Psr\Log\LoggerInterface;

/**
 * Sends the ORM's log to any PSR-3 logger (Nesto's Logger, Monolog…) — used when the `logger` option is a
 * Psr\Log\LoggerInterface. Queries are `debug`, failed queries `error`, slow queries `warning`, schema and
 * migration events `info`; the SQL, parameters and time are in the context.
 */
final class PsrLogger extends AbstractLogger
{
    /** @param bool|string|list<string> $options the `logging` option */
    public function __construct(private readonly LoggerInterface $logger, bool|string|array $options = false)
    {
        parent::__construct($options);
    }

    public function psr(): LoggerInterface
    {
        return $this->logger;
    }

    protected function writeLog(string $level, array $messages, ?QueryRunner $queryRunner = null): void
    {
        $first = $messages[0];
        $type = $first['type'] ?? $level;
        $context = [];
        if (($first['format'] ?? null) === 'sql') {
            $context['query'] = (string) $first['message'];
            $context['parameters'] = $first['parameters'] ?? [];
        }
        if (isset($first['additionalInfo']['time'])) {
            $context['time'] = $first['additionalInfo']['time'];
        }
        $text = implode(' ', array_map(
            fn (array $m) => (isset($m['prefix']) ? $m['prefix'] . ' ' : '') . $m['message'],
            $this->prepareLogMessages($messages),
        ));
        match ($type) {
            'query' => $this->logger->debug($text, $context),
            'query-error', 'error' => $this->logger->error($text, $context),
            'query-slow', 'warn' => $this->logger->warning($text, $context),
            'info', 'schema-build', 'migration' => $this->logger->info($text, $context),
            default => $this->logger->notice($text, $context),
        };
    }
}
