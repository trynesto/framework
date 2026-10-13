<?php

declare(strict_types=1);

namespace Nesto\Orm\Logging;

/**
 * Where the console loggers write: STDERR by default (never STDOUT — under FrankenPHP / php-fpm that is the
 * HTTP response), or any stream / file path given. Opened lazily, once per logger.
 */
final class StreamOutput
{
    /** @var resource|null */
    private $handle = null;

    /** @param resource|string|null $target a stream, a file path, or null for php://stderr */
    public function __construct(private readonly mixed $target = null)
    {
        if (is_resource($target)) {
            $this->handle = $target;
        }
    }

    public function write(string $line): void
    {
        if ($this->handle === null) {
            $handle = @fopen(is_string($this->target) ? $this->target : 'php://stderr', 'ab');
            if ($handle === false) {
                error_log($line);

                return;
            }
            $this->handle = $handle;
        }
        fwrite($this->handle, $line . "\n");
    }

    /** Does the stream support ANSI colors (an interactive terminal)? */
    public function isTerminal(): bool
    {
        if ($this->target !== null && ! is_resource($this->target)) {
            return false;
        }
        if (getenv('NO_COLOR') !== false) {
            return false;
        }
        $stream = $this->handle ?? (defined('STDERR') ? STDERR : null);

        return $stream !== null && function_exists('stream_isatty') && @stream_isatty($stream);
    }
}
