<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Inverse of a MorphTo returning a list (Laravel's morphMany). `name` is the target's morph name.
 *
 *   #[MorphMany(Comment::class, 'commentable')] public array $comments;   // comments.commentable_type/_id
 *
 * The type / id column names default to the ones of the target's #[MorphTo] with that name (else
 * `{name}_type` / `{name}_id`); `localKey` is the key of this entity stored in the id column (primary key).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class MorphMany
{
    /** @param class-string $target */
    public function __construct(
        public readonly string $target,
        public readonly string $name,
        public readonly ?string $type = null,
        public readonly ?string $id = null,
        public readonly ?string $localKey = null,
        public readonly bool $eager = false,
    ) {}
}
