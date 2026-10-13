<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Inverse of a MorphToMany (Laravel's morphedByMany): the entities of ONE class attached to this one.
 *
 *   #[MorphedByMany(Post::class, 'taggable')]  public array $posts;    // on Tag
 *   #[MorphedByMany(Video::class, 'taggable')] public array $videos;
 *
 * Defaults: table = plural of `name`, foreignPivotKey = `{this entity}_id` (e.g. tag_id),
 * relatedPivotKey = `{name}_id`, type = `{name}_type`.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class MorphedByMany
{
    /** @param class-string $target */
    public function __construct(
        public readonly string $target,
        public readonly string $name,
        public readonly ?string $table = null,
        public readonly ?string $foreignPivotKey = null,
        public readonly ?string $relatedPivotKey = null,
        public readonly ?string $type = null,
        public readonly bool $eager = false,
    ) {}
}
