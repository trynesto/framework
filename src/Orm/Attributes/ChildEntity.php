<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * A child of a single table inheritance root (TypeORM's @ChildEntity): stored in the root's table with its
 * discriminator value (default: the short class name). Children may extend other children.
 *
 *   #[ChildEntity('photo')] class Photo extends Content { #[Column] public int $size; }
 *
 * Its repository only sees rows of its own type (and of its child types); the root's repository returns every
 * row, hydrated as the class its discriminator names.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ChildEntity
{
    public function __construct(public readonly ?string $discriminatorValue = null) {}
}
