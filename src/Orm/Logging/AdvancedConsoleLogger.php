<?php

declare(strict_types=1);

namespace Nesto\Orm\Logging;

use Nesto\Orm\QueryRunner;

/**
 * TypeORM's "advanced-console" logger (the default): writes to STDERR with highlighted SQL when it is a
 * terminal.
 *
 *   query: SELECT "u"."id" AS "u__id" FROM "users" AS "u" WHERE "u"."id" = :id -- PARAMETERS: {"id":1}
 *   query failed: INSERT ... / error: SQLSTATE[23505] ...
 *   query is slow: SELECT ... / execution time: 1520
 */
class AdvancedConsoleLogger extends AbstractLogger
{
    private const KEYWORDS = 'SELECT|INSERT|UPDATE|DELETE|FROM|WHERE|AND|OR|NOT|IN|IS|NULL|AS|ON|JOIN|LEFT|RIGHT|INNER|OUTER|ORDER|BY|GROUP|HAVING|LIMIT|OFFSET|VALUES|SET|INTO|CREATE|ALTER|DROP|TABLE|VIEW|INDEX|PRIMARY|KEY|FOREIGN|REFERENCES|DEFAULT|DISTINCT|COUNT|UNION|ALL|CASE|WHEN|THEN|ELSE|END|BEGIN|COMMIT|ROLLBACK|START|TRANSACTION|SAVEPOINT|RELEASE|RETURNING|CONFLICT|DO|DUPLICATE|ASC|DESC|LIKE|BETWEEN|EXISTS|FOR|LOCK|MATERIALIZED|CURSOR|DECLARE|FETCH|CLOSE';

    protected readonly StreamOutput $output;
    private readonly bool $colors;

    /**
     * @param bool|string|list<string> $options the `logging` option
     * @param resource|string|null     $output  stream or file path (default STDERR)
     */
    public function __construct(bool|string|array $options = false, mixed $output = null, ?bool $colors = null)
    {
        parent::__construct($options);
        $this->output = new StreamOutput($output);
        $this->colors = $colors ?? $this->output->isTerminal();
    }

    protected function writeLog(string $level, array $messages, ?QueryRunner $queryRunner = null): void
    {
        foreach ($this->prepareLogMessages($messages, highlight: $this->colors ? $this->highlight(...) : null) as $message) {
            $text = (string) $message['message'];
            $prefix = $message['prefix'] ?? null;
            $line = match ($message['type'] ?? $level) {
                'info', 'query' => $prefix !== null ? $this->color($prefix, '4;90') . ' ' . $text : $text,
                'warn', 'query-slow' => $prefix !== null ? $this->color($prefix, '4;33') . ' ' . $text : $this->color($text, '33'),
                'error', 'query-error' => $prefix !== null ? $this->color($prefix, '4;31') . ' ' . $text : $this->color($text, '31'),
                default => $text, // log, schema-build, migration
            };
            $this->output->write($line);
        }
    }

    private function color(string $text, string $code): string
    {
        return $this->colors ? "\033[{$code}m$text\033[0m" : $text;
    }

    private function highlight(string $sql): string
    {
        $sql = (string) preg_replace('/\b(' . self::KEYWORDS . ')\b/i', "\033[34m$1\033[0m", $sql);

        return (string) preg_replace('/(\'(?:[^\']|\'\')*\'|:[A-Za-z_][A-Za-z0-9_]*)/', "\033[32m$1\033[0m", $sql);
    }
}
