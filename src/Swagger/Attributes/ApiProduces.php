<?php

declare(strict_types=1);

namespace Nesto\Swagger\Attributes;

use Attribute;

/** Response content types: `#[ApiProduces('text/csv')]`. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class ApiProduces
{
    /** @var list<string> */
    public readonly array $types;

    public function __construct(string ...$types)
    {
        $this->types = array_values($types);
    }
}
