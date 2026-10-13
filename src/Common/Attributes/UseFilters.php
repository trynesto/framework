<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class UseFilters
{
    /** @var list<class-string|object> */
    public readonly array $filters;

    public function __construct(string|object ...$filters)
    {
        $this->filters = array_values($filters);
    }
}
