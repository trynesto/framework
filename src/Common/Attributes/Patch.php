<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class Patch extends RouteAttribute
{
    public function method(): string
    {
        return 'PATCH';
    }
}
