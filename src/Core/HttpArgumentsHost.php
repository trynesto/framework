<?php

declare(strict_types=1);

namespace Nesto\Core;

use Nesto\Http\Request;
use Nesto\Http\Response;

final class HttpArgumentsHost
{
    public function __construct(private readonly Request $request, private readonly Response $response) {}

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getResponse(): Response
    {
        return $this->response;
    }
}
