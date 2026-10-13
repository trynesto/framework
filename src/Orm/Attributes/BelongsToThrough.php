<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Read-only inverse of a through relation (staudenmeir/belongs-to-through): Post → user → country,
 * following foreign keys through one or more intermediate entities.
 *
 *   #[BelongsToThrough(Country::class, through: User::class)] public ?Country $country = null;        // on Post
 *   #[BelongsToThrough(Country::class, through: [User::class, Post::class])] public ?Country $country = null; // on Comment
 *
 * `through` may be listed from the target side (like the Laravel package: [User, Post] for
 * Comment → post → user → country) or from this entity's side ([Post, User]); the order is detected
 * from the ManyToOne relations. Keys default to those relations, else `{entity}_id` → primary key:
 *   foreignKeys: [User::class => 'author_id']   FK column (in the previous table) pointing at User
 *   localKeys:   [User::class => 'uuid']        column of User that this FK references
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class BelongsToThrough
{
    /**
     * @param class-string                      $target
     * @param class-string|list<class-string>   $through
     * @param array<class-string, string>       $foreignKeys
     * @param array<class-string, string>       $localKeys
     */
    public function __construct(
        public readonly string $target,
        public readonly string|array $through,
        public readonly array $foreignKeys = [],
        public readonly array $localKeys = [],
        public readonly bool $eager = false,
    ) {}
}
