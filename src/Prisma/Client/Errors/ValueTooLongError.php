<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Errors;

/** P2000 — the value is too long for the column type. */
final class ValueTooLongError extends PrismaClientKnownRequestError
{
}
