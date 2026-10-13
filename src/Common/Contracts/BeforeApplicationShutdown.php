<?php

declare(strict_types=1);

namespace Nesto\Common\Contracts;

/** Lifecycle hook, called by the framework in the same order as NestJS. */
interface BeforeApplicationShutdown
{
    public function beforeApplicationShutdown(?string $signal = null): void;
}
