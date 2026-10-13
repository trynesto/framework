<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Errors;

/** P2002 — a unique constraint failed; `meta['target']` lists the fields. */
final class UniqueConstraintError extends PrismaClientKnownRequestError
{
}
