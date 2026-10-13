<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;

/** Inject by token instead of by type-hint: `#[Inject('APP_NAME')] string $name`. */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
class Inject
{
    public function __construct(public readonly string $token) {}
}
