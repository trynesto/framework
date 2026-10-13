<?php

declare(strict_types=1);

namespace Nesto\Common;

final class ForwardRef
{
    public function __construct(private readonly \Closure $resolver) {}

    public function resolve(): string
    {
        return ($this->resolver)();
    }
}
