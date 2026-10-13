<?php

declare(strict_types=1);

namespace Nesto\Logger\Channels;

/** Several channels at once (LOG_CHANNEL=stack, LOG_STACK=file,stderr). A failing channel never stops the others. */
final class StackChannel implements LogChannel
{
    /** @param list<LogChannel> $channels */
    public function __construct(private readonly array $channels)
    {
    }

    /** @return list<LogChannel> */
    public function channels(): array
    {
        return $this->channels;
    }

    public function write(string $level, string $plain, string $colored): void
    {
        foreach ($this->channels as $channel) {
            try {
                $channel->write($level, $plain, $colored);
            } catch (\Throwable) {
                // logging must never break the request: the other channels still get the entry
            }
        }
    }

    public function close(): void
    {
        foreach ($this->channels as $channel) {
            $channel->close();
        }
    }
}
