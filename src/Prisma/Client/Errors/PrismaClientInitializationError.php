<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Errors;

use Nesto\Prisma\PrismaException;

/**
 * Prisma's `PrismaClientInitializationError`: the client could not start — no datasource URL, the database
 * cannot be reached (`errorCode` P1001) or does not exist (P1003), the schema is invalid.
 */
final class PrismaClientInitializationError extends PrismaException
{
    public function __construct(string $message, public readonly ?string $errorCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
