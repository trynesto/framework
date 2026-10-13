<?php

declare(strict_types=1);

namespace Nesto\Prisma\Migrate;

/** What `migrate dev` did. */
final class DevResult
{
    /** @var list<string> migrations applied to the database (pending ones and the new one) */
    public array $applied = [];
    /** The migration created from schema changes, if any. */
    public ?LocalMigration $created = null;
    /** True when the database was reset (drift, modified or failed migrations): the seed should run. */
    public bool $reset = false;
}
