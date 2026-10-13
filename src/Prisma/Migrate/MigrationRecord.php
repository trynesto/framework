<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

/** One row of `_prisma_migrations`. */
final class MigrationRecord
{
    public function __construct(
        public readonly string $id,
        public readonly string $checksum,
        public readonly string $name,
        public readonly ?string $startedAt,
        public readonly ?string $finishedAt,
        public readonly ?string $rolledBackAt,
        public readonly ?string $logs,
        public readonly int $appliedStepsCount,
    ) {}

    public function isApplied(): bool
    {
        return $this->finishedAt !== null && $this->rolledBackAt === null;
    }

    /** Started, never finished, not marked as rolled back: blocks deploy until resolved. */
    public function isFailed(): bool
    {
        return $this->finishedAt === null && $this->rolledBackAt === null;
    }

    public function isRolledBack(): bool
    {
        return $this->rolledBackAt !== null;
    }
}
