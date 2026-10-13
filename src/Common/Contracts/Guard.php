<?php

declare(strict_types=1);

namespace Nesto\Common\Contracts;

use Nesto\Core\ExecutionContext;

/** Nest's CanActivate. Return false (or throw) to deny the request. */
interface Guard
{
    public function canActivate(ExecutionContext $context): bool;
}
