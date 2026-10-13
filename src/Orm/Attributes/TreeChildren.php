<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Child nodes of a #[Tree] entity (TypeORM's @TreeChildren): the OneToMany inverse of #[TreeParent].
 *
 *   #[TreeChildren(cascade: true)] public array $children;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class TreeChildren
{
    /** @param bool|list<'insert'|'update'|'remove'|'soft-remove'|'recover'> $cascade */
    public function __construct(
        public readonly bool|array $cascade = false,
        public readonly bool $eager = false,
    ) {}
}
