<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Many-to-many relation. The side carrying #[JoinTable] owns the join table.
 *
 *   #[ManyToMany(Tag::class, inverseSide: 'posts')] #[JoinTable] public array $tags;   // owning
 *   #[ManyToMany(Post::class, inverseSide: 'tags')] public array $posts;               // inverse
 *
 * save() writes the join-table rows from either side. Options: cascade (true | ['insert', 'update',
 * 'remove', 'soft-remove', 'recover']), eager, lazy (LazyCollection, PHP 8.4+: declare `public iterable $tags`).
 * On the owning side, `onDelete` / `onUpdate` set the actions of both join-table foreign keys
 * (default CASCADE / none) and `createForeignKeyConstraints: false` creates none.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ManyToMany
{
    /**
     * @param class-string $target
     * @param bool|list<'insert'|'update'|'remove'|'soft-remove'|'recover'> $cascade
     */
    public function __construct(
        public readonly string $target,
        public readonly ?string $inverseSide = null,
        public readonly bool|array $cascade = false,
        public readonly bool $eager = false,
        public readonly bool $lazy = false,
        public readonly ?string $onDelete = null,
        public readonly ?string $onUpdate = null,
        public readonly bool $createForeignKeyConstraints = true,
    ) {}
}
