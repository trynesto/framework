<?php

declare(strict_types=1);

namespace Nesto\Common\Contracts;

use Nesto\Core\MiddlewareConsumer;

/** Nest's NestModule: implement to bind middleware to routes. */
interface NestoModule
{
    public function configure(MiddlewareConsumer $consumer): void;
}
