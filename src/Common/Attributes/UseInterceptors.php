<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class UseInterceptors
{
    /** @var list<class-string|object> */
    public readonly array $interceptors;

    public function __construct(string|object ...$interceptors)
    {
        $this->interceptors = array_values($interceptors);
    }
}
