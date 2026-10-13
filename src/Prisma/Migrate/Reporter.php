<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

/**
 * How migrate / db operations talk to the user (the CLI commands implement it over their Output / Input;
 * tests use BufferedReporter). confirm() / ask() return the default when the session is not interactive.
 */
interface Reporter
{
    public function info(string $message): void;

    public function warn(string $message): void;

    public function confirm(string $question, bool $default = false): bool;

    public function ask(string $question, ?string $default = null): ?string;

    /** Whether confirm() / ask() can reach a person. */
    public function interactive(): bool;
}
