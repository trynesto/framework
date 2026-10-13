<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Query extends ParamAttribute
{
    public function type(): string
    {
        return 'query';
    }
}
