<?php

declare(strict_types=1);

namespace Nesto\Common\Contracts;

use Nesto\Core\ExecutionContext;

/**
 * Nest's NestInterceptor, minus RxJS: run code before, call $next->handle(), transform/return the result.
 */
interface Interceptor
{
    public function intercept(ExecutionContext $context, CallHandler $next): mixed;
}
