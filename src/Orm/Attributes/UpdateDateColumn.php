<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/** Set to "now" on every save that changes the row. `#[UpdateDateColumn] public ?\DateTimeImmutable $updatedAt = null;` */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class UpdateDateColumn
{
    public function __construct(public readonly ?string $name = null) {}
}
