<?php

declare(strict_types=1);

namespace Nesto\Common\Contracts;

/** The "next" link given to an Interceptor. handle() runs the rest of the chain and returns the handler result. */
interface CallHandler
{
    public function handle(): mixed;
}
