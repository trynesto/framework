<?php

declare(strict_types=1);

namespace Nesto\Orm\Logging;

use Nesto\Orm\QueryRunner;

/** TypeORM's "simple-console" logger: plain lines on STDERR, no colors. */
class SimpleConsoleLogger extends AbstractLogger
{
    protected readonly StreamOutput $output;

    /**
     * @param bool|string|list<string> $options the `logging` option
     * @param resource|string|null     $output  stream or file path (default STDERR)
     */
    public function __construct(bool|string|array $options = false, mixed $output = null)
    {
        parent::__construct($options);
        $this->output = new StreamOutput($output);
    }

    protected function writeLog(string $level, array $messages, ?QueryRunner $queryRunner = null): void
    {
        foreach ($this->prepareLogMessages($messages) as $message) {
            $prefix = $message['prefix'] ?? null;
            $this->output->write(($prefix !== null ? $prefix . ' ' : '') . $message['message']);
        }
    }
}
