<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Inverse side of a ManyToOne. `inverseSide` names the ManyToOne property on the target.
 *
 *   #[OneToMany(Post::class, inverseSide: 'user')] public array $posts;
 *   #[OneToMany(Post::class, inverseSide: 'user', cascade: true)] public array $posts;     // save() inserts new posts
 *   #[OneToMany(Post::class, inverseSide: 'user', lazy: true)] public iterable $posts;    // LazyCollection (PHP 8.4+)
 *
 * Posts removed from the collection are handled by the ManyToOne's `orphanedRowAction` when the user is saved.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class OneToMany
{
    /**
     * @param class-string $target
     * @param bool|list<'insert'|'update'|'remove'|'soft-remove'|'recover'> $cascade
     */
    public function __construct(
        public readonly string $target,
        public readonly string $inverseSide,
        public readonly bool|array $cascade = false,
        public readonly bool $eager = false,
        public readonly bool $lazy = false,
    ) {}
}
