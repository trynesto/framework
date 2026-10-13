<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Errors;

/** P2011 — a NOT NULL constraint failed; `meta['constraint']` lists the fields. */
final class NullConstraintError extends PrismaClientKnownRequestError
{
}
