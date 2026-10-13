<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class UsePipes
{
    /** @var list<class-string|object> */
    public readonly array $pipes;

    public function __construct(string|object ...$pipes)
    {
        $this->pipes = array_values($pipes);
    }
}
