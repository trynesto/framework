<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Errors;

/** P2003 — a foreign key constraint failed; `meta['field_name']` names the constraint. */
final class ForeignKeyConstraintError extends PrismaClientKnownRequestError
{
}
