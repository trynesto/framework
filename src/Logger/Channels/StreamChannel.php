<?php

declare(strict_types=1);

namespace Nesto\Logger\Channels;

/**
 * Writes to stdout, stderr or any stream resource (LOG_CHANNEL=stdout / stderr: containers, FrankenPHP).
 * `StreamChannel::console()` is the default without configuration: errors and fatals on stderr, the rest on
 * stdout. Colours are used when the target is a TTY (or with `colors: true`).
 */
final class StreamChannel implements LogChannel
{
    /** @param 'stdout'|'stderr'|'console'|resource $target */
    public function __construct(private readonly mixed $target, private readonly ?bool $colors = null)
    {
    }

    public static function stdout(): self
    {
        return new self('stdout');
    }

    public static function stderr(): self
    {
        return new self('stderr');
    }

    /** Errors and fatals on stderr, everything else on stdout. */
    public static function console(): self
    {
        return new self('console');
    }

    public function write(string $level, string $plain, string $colored): void
    {
        if (is_resource($this->target)) {
            fwrite($this->target, ($this->colors === true ? $colored : $plain) . PHP_EOL);

            return;
        }
        $name = match ($this->target) {
            'stdout' => 'stdout',
            'stderr' => 'stderr',
            default => in_array($level, ['error', 'fatal'], true) ? 'stderr' : 'stdout',
        };
        $line = ($this->colors ?? \Nesto\Logger\Logger::colorSetting() ?? self::isTty($name)) ? $colored : $plain;
        // a fresh handle per entry: the STDOUT / STDERR constants do not exist on web SAPIs
        $handle = @fopen('php://' . $name, 'wb');
        if ($handle !== false) {
            fwrite($handle, $line . PHP_EOL);
            fclose($handle);
        }
    }

    public function close(): void
    {
    }

    /** @return 'stdout'|'stderr'|'console'|resource */
    public function target(): mixed
    {
        return $this->target;
    }

    private static function isTty(string $name): bool
    {
        $constant = $name === 'stderr' ? 'STDERR' : 'STDOUT';

        return defined($constant) && function_exists('stream_isatty') && @stream_isatty(constant($constant));
    }
}
