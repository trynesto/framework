<?php

declare(strict_types=1);

namespace Nesto\Core\Routing;

final class RouteMatch
{
    /** @param array<string, string> $params @param array<string, string> $hostParams `:account` of #[Controller(host: ':account.example.com')] */
    public function __construct(public readonly Route $route, public readonly array $params, public readonly array $hostParams = []) {}
}
