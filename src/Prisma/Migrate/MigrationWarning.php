<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

/**
 * A destructive or risky step of a migration plan, worded like Prisma's warnings. `unexecutable` steps cannot
 * run on the current data at all (db push needs --force-reset for them); the others may lose data (db push
 * needs --accept-data-loss).
 */
final class MigrationWarning
{
    public function __construct(public readonly string $message, public readonly bool $unexecutable = false) {}

    public function __toString(): string
    {
        return $this->message;
    }
}
