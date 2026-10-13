<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Errors;

use Nesto\Prisma\PrismaException;

/**
 * Prisma's `PrismaClientValidationError`: the query arguments are invalid (unknown argument or field, wrong type,
 * missing unique criteria, select and include together, ...). Nothing was sent to the database.
 */
final class PrismaClientValidationError extends PrismaException
{
}
