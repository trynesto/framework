<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/** Set to "now" when the entity is first inserted. `#[CreateDateColumn] public ?\DateTimeImmutable $createdAt = null;` */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class CreateDateColumn
{
    public function __construct(public readonly ?string $name = null) {}
}
