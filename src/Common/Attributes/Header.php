<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Header
{
    public function __construct(public readonly string $name, public readonly string $value) {}
}
