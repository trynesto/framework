<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Errors;

use Nesto\Prisma\PrismaException;

/** Prisma's `PrismaClientUnknownRequestError`: the database reported an error without a Prisma error code. */
final class PrismaClientUnknownRequestError extends PrismaException
{
}
