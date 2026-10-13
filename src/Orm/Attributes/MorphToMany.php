<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Polymorphic many-to-many, on the side whose type is stored in the pivot (Laravel's morphToMany).
 *
 *   #[MorphToMany(Tag::class, 'taggable')] public array $tags;   // pivot "taggables": tag_id, taggable_type, taggable_id
 *
 * Defaults: table = plural of `name`, foreignPivotKey = `{name}_id` (this entity's id),
 * relatedPivotKey = `{target}_id`, type = `{name}_type`.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class MorphToMany
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
