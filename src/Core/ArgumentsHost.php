<?php

declare(strict_types=1);

namespace Nesto\Core;

use Nesto\Http\Request;
use Nesto\Http\Response;

/** Nest's ArgumentsHost: gives filters access to the underlying request/response. */
class ArgumentsHost
{
    public function __construct(
        protected readonly Request $request,
        protected readonly Response $response,
        protected string $type = 'http',
    ) {}

    public function getType(): string
    {
        return $this->type;
    }

    public function switchToHttp(): HttpArgumentsHost
    {
        return new HttpArgumentsHost($this->request, $this->response);
    }

    /** @return array{0: Request, 1: Response} */
    public function getArgs(): array
    {
        return [$this->request, $this->response];
    }
}
