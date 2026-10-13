<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Errors;

/** P2028 — transaction API error (timeout, closed transaction). */
final class TransactionError extends PrismaClientKnownRequestError
{
}
