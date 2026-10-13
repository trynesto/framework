<?php

declare(strict_types=1);

namespace Nesto\Core;

use Nesto\Core\Routing\Route;
use Nesto\Http\Request;
use Nesto\Http\Response;
use ReflectionClass;
use ReflectionMethod;

/** Nest's ExecutionContext: ArgumentsHost + which controller/handler is about to run. */
final class ExecutionContext extends ArgumentsHost
{
    public function __construct(
        Request $request,
        Response $response,
        private readonly Route $route,
        private readonly ?object $controllerInstance = null,
    ) {
        parent::__construct($request, $response);
    }

    /** @return class-string */
    public function getClassName(): string
    {
        return $this->route->controller;
    }

    public function getClass(): ReflectionClass
    {
        return $this->route->classReflection();
    }

    public function getHandler(): ReflectionMethod
    {
        return $this->route->methodReflection();
    }

    public function getHandlerName(): string
    {
        return $this->route->handler;
    }

    public function getRoute(): Route
    {
        return $this->route;
    }

    public function getInstance(): ?object
    {
        return $this->controllerInstance;
    }
}
