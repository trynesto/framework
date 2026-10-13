<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Enables soft deletes: softDelete() stamps this column, and all finds exclude stamped rows
 * unless `withDeleted` is requested. `#[DeleteDateColumn] public ?\DateTimeImmutable $deletedAt = null;`
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class DeleteDateColumn
{
    public function __construct(public readonly ?string $name = null) {}
}
