<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;
use Nesto\Common\Scope;

#[Attribute(Attribute::TARGET_CLASS)]
final class Injectable
{
    public function __construct(public readonly Scope $scope = Scope::DEFAULT) {}
}
