<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;

/** Attach arbitrary metadata readable through Reflector: `#[SetMetadata('roles', ['admin'])]`. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class SetMetadata
{
    public function __construct(public readonly string $key, public readonly mixed $value = true) {}
}
