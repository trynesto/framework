<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Errors;

/** P2010 — a raw query failed (`meta['code']`, `meta['message']` from the database). */
final class RawQueryError extends PrismaClientKnownRequestError
{
}
