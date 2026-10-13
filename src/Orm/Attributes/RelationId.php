<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Exposes the id(s) of a relation without loading the related entities (TypeORM's @RelationId).
 * Filled whenever the entity is loaded (find*, QueryBuilder, relations); never written by save().
 *
 *   #[ManyToOne(User::class)] public User $author;
 *   #[RelationId('author')] public ?int $authorId = null;           // to-one: the id or null
 *
 *   #[ManyToMany(Tag::class)] #[JoinTable] public array $tags;
 *   #[RelationId('tags')] public array $tagIds = [];                // to-many: list of ids
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class RelationId
{
    public function __construct(public readonly string $relation) {}
}
