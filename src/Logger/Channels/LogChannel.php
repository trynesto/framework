<?php

declare(strict_types=1);

namespace Nesto\Logger\Channels;

/**
 * Where Logger writes its entries (LOG_CHANNEL): a file, stdout/stderr, or several at once.
 *
 *   Logger::useChannel(new FileChannel(storage_path('logs/nesto.log'), daily: true, maxFiles: 14));
 *   Logger::useChannel(new StackChannel([new FileChannel(...), StreamChannel::stderr()]));
 */
interface LogChannel
{
    /**
     * @param string $level   Nest level of the entry (fatal, error, warn, log, debug, verbose)
     * @param string $plain   the formatted entry without colours (several lines for a stack trace)
     * @param string $colored the same entry with ANSI colours, for terminals
     */
    public function write(string $level, string $plain, string $colored): void;

    /** Release open handles (called when the channel is replaced). */
    public function close(): void;
}
