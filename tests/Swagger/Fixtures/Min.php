<?php

declare(strict_types=1);

namespace Nesto\Tests\Swagger\Fixtures;

/** Stand-in for a validation attribute: the generator matches it by short name only. */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Min
{
    public function __construct(public int $min) {}
}
