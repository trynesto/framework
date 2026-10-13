<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

/** `migrate status`: local migrations compared with `_prisma_migrations`. */
final class MigrationStatus
{
    /** @var list<string> */
    public array $local = [];
    /** @var list<string> */
    public array $applied = [];
    /** @var list<string> local migrations not applied yet */
    public array $pending = [];
    /** @var list<string> */
    public array $failed = [];
    /** @var list<string> applied to the database but missing from prisma/migrations */
    public array $missingLocally = [];

    public function isUpToDate(): bool
    {
        return $this->pending === [] && $this->failed === [] && $this->missingLocally === [];
    }
}
