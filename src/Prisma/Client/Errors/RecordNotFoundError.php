<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Errors;

/** P2025 — a record the operation depends on was not found (`meta['cause']`). */
final class RecordNotFoundError extends PrismaClientKnownRequestError
{
}
