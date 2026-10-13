<?php

declare(strict_types=1);

namespace Nesto\Common\Contracts;

use Nesto\Core\ArgumentsHost;
use Psr\Http\Message\ResponseInterface;

interface ExceptionFilter
{
    public function catch(\Throwable $exception, ArgumentsHost $host): ResponseInterface;
}
