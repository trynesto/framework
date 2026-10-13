<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

/** A migration folder of prisma/migrations. */
final class LocalMigration
{
    public function __construct(public readonly string $name, public readonly string $path) {}

    public function sql(): string
    {
        return (string) file_get_contents($this->path . '/migration.sql');
    }

    /** SHA-256 of migration.sql, hex — what `_prisma_migrations.checksum` records. */
    public function checksum(): string
    {
        return hash('sha256', $this->sql());
    }
}
