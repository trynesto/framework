<?php

declare(strict_types=1);

namespace Nesto\Orm\Logging;

use Nesto\Orm\QueryRunner;

/**
 * TypeORM's "file" logger: appends to `ormlogs.log` in the project root (or `logPath`, relative to the root
 * unless absolute).
 *
 *   'logger' => 'file', 'logging' => ['query', 'error']
 *   'logger' => new FileLogger(['error'], ['logPath' => 'storage/logs/orm.log'])
 *
 *   [2026-10-13T09:12:01.123Z][QUERY]: SELECT ... -- PARAMETERS: [1]
 *   [2026-10-13T09:12:01.456Z][SLOW QUERY: 1520 ms]: SELECT ...
 */
class FileLogger extends AbstractLogger
{
    private readonly string $path;
    private ?StreamOutput $output = null;

    /**
     * @param bool|string|list<string> $options the `logging` option
     * @param array{logPath?: string}  $fileLoggerOptions
     */
    public function __construct(bool|string|array $options = false, array $fileLoggerOptions = [])
    {
        parent::__construct($options);
        $path = (string) ($fileLoggerOptions['logPath'] ?? 'ormlogs.log');
        $this->path = self::isAbsolute($path) ? $path : self::root() . '/' . $path;
    }

    public function path(): string
    {
        return $this->path;
    }

    protected function writeLog(string $level, array $messages, ?QueryRunner $queryRunner = null): void
    {
        $lines = [];
        foreach ($this->prepareLogMessages($messages, addColonToPrefix: false) as $message) {
            $prefix = $message['prefix'] ?? null;
            $text = (string) $message['message'];
            $line = match ($message['type'] ?? $level) {
                'log' => "[LOG]: $text",
                'info' => "[INFO]: $text",
                'query' => "[QUERY]: $text",
                'warn' => "[WARN]: $text",
                'query-slow' => $prefix === 'execution time' ? null : '[SLOW QUERY: ' . ($message['additionalInfo']['time'] ?? '?') . " ms]: $text",
                'query-error' => $prefix === 'query failed' ? "[FAILED QUERY]: $text" : "[QUERY ERROR]: $text",
                'error' => "[ERROR]: $text",
                default => $text, // schema-build, migration
            };
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        $this->write($lines);
    }

    /** @param list<string> $lines */
    protected function write(array $lines): void
    {
        if ($lines === []) {
            return;
        }
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->output ??= new StreamOutput($this->path);
        $now = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)))->format('Y-m-d\TH:i:s.v\Z');
        foreach ($lines as $line) {
            $this->output->write('[' . $now . ']' . $line);
        }
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private static function root(): string
    {
        if (function_exists('base_path')) {
            try {
                return rtrim((string) base_path(), '/');
            } catch (\Throwable) {
            }
        }

        return (string) getcwd();
    }
}
