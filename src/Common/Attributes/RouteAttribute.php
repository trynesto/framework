<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

abstract class RouteAttribute
{
    /** @var list<string> */
    public readonly array $paths;

    /** @param string|list<string> $path */
    public function __construct(string|array $path = '')
    {
        $this->paths = is_array($path) ? array_values($path) : [$path];
    }

    abstract public function method(): string;
}
