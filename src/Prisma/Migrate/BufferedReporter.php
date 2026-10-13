<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

/** A Reporter that records messages and answers with fixed replies (tests, programmatic use). */
final class BufferedReporter implements Reporter
{
    /** @var list<string> */
    public array $messages = [];

    /** @param list<bool|string|null> $answers replies to confirm() / ask(), in order (then defaults) */
    public function __construct(private array $answers = [], private readonly bool $interactive = false) {}

    public function info(string $message): void
    {
        $this->messages[] = $message;
    }

    public function warn(string $message): void
    {
        $this->messages[] = 'WARN: ' . $message;
    }

    public function confirm(string $question, bool $default = false): bool
    {
        $this->messages[] = 'CONFIRM: ' . $question;

        return $this->answers !== [] ? (bool) array_shift($this->answers) : $default;
    }

    public function ask(string $question, ?string $default = null): ?string
    {
        $this->messages[] = 'ASK: ' . $question;
        $answer = $this->answers !== [] ? array_shift($this->answers) : $default;

        return $answer === null ? $default : (string) $answer;
    }

    public function interactive(): bool
    {
        return $this->interactive || $this->answers !== [];
    }

    public function output(): string
    {
        return implode("\n", $this->messages);
    }
}
