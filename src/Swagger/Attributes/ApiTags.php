<?php

declare(strict_types=1);

namespace Nesto\Swagger\Attributes;

use Attribute;

/** Group operations under tags: `#[ApiTags('users')]` on a controller or a handler. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class ApiTags
{
    /** @var list<string> */
    public readonly array $tags;

    public function __construct(string ...$tags)
    {
        $this->tags = array_values($tags);
    }
}
