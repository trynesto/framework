<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Read-only "has many through" (Laravel's hasManyThrough): Country → users → posts.
 *
 *   #[HasManyThrough(Post::class, through: User::class)] public array $posts;     // on Country
 *
 * Keys (column or property names) default to the ManyToOne relations between the entities, else to
 * Laravel's conventions:
 *   firstKey       FK on the intermediate table pointing at this entity      (users.country_id)
 *   secondKey      FK on the target table pointing at the intermediate       (posts.user_id)
 *   localKey       key on this entity referenced by firstKey                 (countries.id)
 *   secondLocalKey key on the intermediate referenced by secondKey           (users.id)
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class HasManyThrough
{
    /**
     * @param class-string $target
     * @param class-string $through
     */
    public function __construct(
        public readonly string $target,
        public readonly string $through,
        public readonly ?string $firstKey = null,
        public readonly ?string $secondKey = null,
        public readonly ?string $localKey = null,
        public readonly ?string $secondLocalKey = null,
        public readonly bool $eager = false,
    ) {}
}
