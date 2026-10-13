<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Non-generated primary key (you assign the value yourself).
 *
 *   #[PrimaryColumn] public string $code;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class PrimaryColumn
{
    public function __construct(public readonly ?string $name = null, public readonly ?string $type = null) {}
}
