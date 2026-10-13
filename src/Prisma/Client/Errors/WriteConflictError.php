<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Errors;

/** P2034 — write conflict or deadlock; retry the transaction. */
final class WriteConflictError extends PrismaClientKnownRequestError
{
}
