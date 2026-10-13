<?php

declare(strict_types=1);

namespace Nesto\Orm\Logging;

use Nesto\Orm\Mapping\TypeConverter;
use Nesto\Orm\QueryRunner;

/**
 * TypeORM's AbstractLogger: filters events by the `logging` option and hands the enabled ones to writeLog()
 * as messages ({type, prefix, message, format, parameters, additionalInfo}).
 *
 * `logging`: 'all' (everything), true (queries and query errors only — TypeORM 0.3's AbstractLogger: schema, log, info and
 * warn need 'all' or their level listed), false (only slow queries and migrations, which are always logged —
 * like TypeORM), or a list of 'query' | 'error' | 'schema' | 'warn' | 'info' | 'log' | 'migration'.
 */
abstract class AbstractLogger implements Logger
{
    /** @param bool|string|list<string> $options the `logging` option */
    public function __construct(protected bool|string|array $options = false) {}

    public function logQuery(string $query, array $parameters = [], ?QueryRunner $queryRunner = null): void
    {
        if ($this->isLogEnabledFor('query')) {
            $this->writeLog('query', [['type' => 'query', 'prefix' => 'query', 'message' => $query, 'format' => 'sql', 'parameters' => $parameters]], $queryRunner);
        }
    }

    public function logQueryError(string|\Throwable $error, string $query, array $parameters = [], ?QueryRunner $queryRunner = null): void
    {
        if ($this->isLogEnabledFor('query-error')) {
            $this->writeLog('warn', [
                ['type' => 'query-error', 'prefix' => 'query failed', 'message' => $query, 'format' => 'sql', 'parameters' => $parameters],
                ['type' => 'query-error', 'prefix' => 'error', 'message' => $error instanceof \Throwable ? $error->getMessage() : $error],
            ], $queryRunner);
        }
    }

    public function logQuerySlow(float $time, string $query, array $parameters = [], ?QueryRunner $queryRunner = null): void
    {
        if ($this->isLogEnabledFor('query-slow')) {
            $this->writeLog('warn', [
                ['type' => 'query-slow', 'prefix' => 'query is slow', 'message' => $query, 'format' => 'sql', 'parameters' => $parameters, 'additionalInfo' => ['time' => $time]],
                ['type' => 'query-slow', 'prefix' => 'execution time', 'message' => $time],
            ], $queryRunner);
        }
    }

    public function logSchemaBuild(string $message, ?QueryRunner $queryRunner = null): void
    {
        if ($this->isLogEnabledFor('schema-build')) {
            $this->writeLog('schema', [['type' => 'schema-build', 'message' => $message]], $queryRunner);
        }
    }

    public function logMigration(string $message, ?QueryRunner $queryRunner = null): void
    {
        if ($this->isLogEnabledFor('migration')) {
            $this->writeLog('log', [['type' => 'migration', 'message' => $message]], $queryRunner);
        }
    }

    public function log(string $level, mixed $message, ?QueryRunner $queryRunner = null): void
    {
        if (! in_array($level, ['log', 'info', 'warn'], true) || ! $this->isLogEnabledFor($level)) {
            return;
        }
        $entry = ['type' => $level, 'message' => is_scalar($message) || $message instanceof \Stringable ? (string) $message : self::json($message)];
        if ($level === 'info') {
            $entry['prefix'] = 'info';
        }
        $this->writeLog($level, [$entry], $queryRunner);
    }

    /** Is this level ('query', 'schema', …) or message type ('query-error', 'query-slow', …) enabled? */
    public function isLogEnabledFor(string $type): bool
    {
        $all = $this->options === true || $this->options === 'all';
        $listed = fn (string $level): bool => $all || (is_array($this->options) && in_array($level, $this->options, true))
            || (is_string($this->options) && $this->options === $level);

        return match ($type) {
            'query' => $listed('query'),
            'error', 'query-error' => $listed('error'),
            'query-slow', 'migration' => true,
            'schema', 'schema-build' => $this->options === 'all' || (is_array($this->options) && in_array('schema', $this->options, true)) || $this->options === 'schema',
            'log', 'info', 'warn' => $this->options === 'all' || (is_array($this->options) && in_array($type, $this->options, true)) || $this->options === $type,
            default => false,
        };
    }

    /**
     * Write messages to the output.
     *
     * @param 'query'|'schema'|'warn'|'info'|'log' $level
     * @param list<array{type: string, prefix?: string, message: string|int|float, format?: string, parameters?: array, additionalInfo?: array}> $messages
     */
    abstract protected function writeLog(string $level, array $messages, ?QueryRunner $queryRunner = null): void;

    /**
     * SQL messages get their parameters appended as a comment ("… -- PARAMETERS: [1,"a"]"), prefixes a colon.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    protected function prepareLogMessages(array $messages, bool $appendParameterAsComment = true, bool $addColonToPrefix = true, ?\Closure $highlight = null): array
    {
        foreach ($messages as &$message) {
            if (($message['format'] ?? null) === 'sql') {
                $sql = (string) $message['message'];
                if ($appendParameterAsComment && ! empty($message['parameters'])) {
                    $sql .= ' -- PARAMETERS: ' . $this->stringifyParams($message['parameters']);
                }
                $message['message'] = $highlight !== null ? $highlight($sql) : $sql;
            }
            if ($addColonToPrefix && isset($message['prefix'])) {
                $message['prefix'] .= ':';
            }
        }

        return $messages;
    }

    /** @param array<string|int, mixed> $parameters */
    protected function stringifyParams(array $parameters): string
    {
        return self::json(array_map(static function (mixed $value): mixed {
            try {
                $value = TypeConverter::toParameter($value);
            } catch (\Throwable) {
                return '[unserializable]';
            }

            return is_resource($value) ? '[resource]' : $value;
        }, $parameters));
    }

    private static function json(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? '[unserializable]' : $json;
    }
}
