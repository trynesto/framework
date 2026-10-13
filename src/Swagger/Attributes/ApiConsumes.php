<?php

declare(strict_types=1);

namespace Nesto\Swagger\Attributes;

use Attribute;

/** Request body content types: `#[ApiConsumes('multipart/form-data')]`. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class ApiConsumes
{
    /** @var list<string> */
    public readonly array $types;

    public function __construct(string ...$types)
    {
        $this->types = array_values($types);
    }
}
