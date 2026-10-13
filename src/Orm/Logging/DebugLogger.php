<?php

declare(strict_types=1);

namespace Nesto\Orm\Logging;

use Nesto\Orm\QueryRunner;

/**
 * TypeORM's "debug" logger: like the `debug` npm package, it writes to STDERR only the namespaces enabled
 * by the DEBUG environment variable (comma / space separated, `*` wildcards, `-` to exclude):
 *
 *   DEBUG=typeorm:*            everything
 *   DEBUG=typeorm:query*       queries, failed and slow queries
 *   namespaces: typeorm:query, typeorm:query:error, typeorm:query:slow, typeorm:schema-build,
 *               typeorm:migration, typeorm:log, typeorm:info, typeorm:warn
 *
 * The `logging` option is ignored (TypeORM does the same).
 */
class DebugLogger extends AbstractLogger
{
    private const NAMESPACES = [
        'query' => 'typeorm:query', 'query-error' => 'typeorm:query:error', 'error' => 'typeorm:query:error',
        'query-slow' => 'typeorm:query:slow', 'schema' => 'typeorm:schema-build', 'schema-build' => 'typeorm:schema-build',
        'migration' => 'typeorm:migration', 'log' => 'typeorm:log', 'info' => 'typeorm:info', 'warn' => 'typeorm:warn',
    ];

    private readonly StreamOutput $output;

    /** @param resource|string|null $output stream or file path (default STDERR) */
    public function __construct(private readonly ?string $debug = null, mixed $output = null)
    {
        parent::__construct(true);
        $this->output = new StreamOutput($output);
    }

    public function isLogEnabledFor(string $type): bool
    {
        $namespace = self::NAMESPACES[$type] ?? null;

        return $namespace !== null && self::enabled($namespace, $this->debug ?? (string) getenv('DEBUG'));
    }

    protected function writeLog(string $level, array $messages, ?QueryRunner $queryRunner = null): void
    {
        foreach ($this->prepareLogMessages($messages, addColonToPrefix: false) as $message) {
            $type = $message['type'] ?? $level;
            if ($type === 'query-slow' && ($message['prefix'] ?? null) === 'execution time') {
                continue;
            }
            $text = (string) $message['message'];
            if ($type === 'query-slow') {
                $text .= ' -- EXECUTION TIME: ' . ($message['additionalInfo']['time'] ?? '?');
            }
            $this->output->write((self::NAMESPACES[$type] ?? 'typeorm:' . $type) . ' ' . $text);
        }
    }

    /** debug-package matching of a namespace against a DEBUG value. */
    public static function enabled(string $namespace, string $debug): bool
    {
        $enabled = false;
        foreach (preg_split('/[\s,]+/', trim($debug)) ?: [] as $pattern) {
            if ($pattern === '') {
                continue;
            }
            $exclude = str_starts_with($pattern, '-');
            $regex = '/^' . str_replace('\*', '.*?', preg_quote(ltrim($pattern, '-'), '/')) . '$/';
            if (preg_match($regex, $namespace) === 1) {
                if ($exclude) {
                    return false;
                }
                $enabled = true;
            }
        }

        return $enabled;
    }
}
