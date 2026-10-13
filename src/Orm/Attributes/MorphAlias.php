<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Short name stored in polymorphic type columns instead of the class name (an entry of the morph map).
 *
 *   #[Entity, MorphAlias('post')] final class Post { ... }   // comments.commentable_type = 'post'
 *
 * Same effect as the OrmModule / DataSource option `morphMap: ['post' => Post::class]`.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class MorphAlias
{
    public function __construct(public readonly string $alias) {}
}
