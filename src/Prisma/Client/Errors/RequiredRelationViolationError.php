<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Errors;

/** P2014 — the change would violate a required relation. */
final class RequiredRelationViolationError extends PrismaClientKnownRequestError
{
}
