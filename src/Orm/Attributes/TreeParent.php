<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Parent node of a #[Tree] entity (TypeORM's @TreeParent): a ManyToOne to the entity itself, foreign key
 * "{property}_id" unless a #[JoinColumn] names it.
 *
 *   #[TreeParent(onDelete: 'CASCADE')] public ?Category $parent = null;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class TreeParent
{
    public function __construct(
        public readonly ?string $onDelete = null,
        public readonly ?string $onUpdate = null,
        public readonly bool $eager = false,
    ) {}
}
