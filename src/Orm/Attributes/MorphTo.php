<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Owning side of a polymorphic relation (Laravel's morphTo): this table stores the related entity's
 * type alias and id, so one property can point at entities of different classes.
 *
 *   #[MorphTo] public ?object $commentable = null;               // columns commentable_type + commentable_id
 *   #[MorphTo(type: 'owner_kind', id: 'owner_ref')] public ?object $owner = null;
 *   #[MorphTo(types: [Post::class, Video::class])]                // optional: known targets (resolves aliases,
 *   public Post|Video|null $commentable = null;                   // and picks the id column type)
 *
 * `name` defaults to the snake_cased property name; the type column stores the morph alias
 * (OrmModule `morphMap` option or #[MorphAlias]) or the class name when there is none.
 * `idType` overrides the id column type (default: the targets' primary key type, else bigint).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class MorphTo
{
    /** @param list<class-string> $types */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $type = null,
        public readonly ?string $id = null,
        public readonly array $types = [],
        public readonly bool $nullable = true,
        public readonly ?string $idType = null,
        public readonly bool $eager = false,
    ) {}
}
