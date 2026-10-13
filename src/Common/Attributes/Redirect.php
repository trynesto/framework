<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class Redirect
{
    public function __construct(public readonly string $url, public readonly int $statusCode = 302) {}
}
