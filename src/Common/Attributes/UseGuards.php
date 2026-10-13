<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class UseGuards
{
    /** @var list<class-string|object> */
    public readonly array $guards;

    public function __construct(string|object ...$guards)
    {
        $this->guards = array_values($guards);
    }
}
