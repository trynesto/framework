<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Foreign key column of a ManyToOne / owning OneToOne. Defaults to `{property}_id` → `id`.
 *
 *   #[JoinColumn(name: 'author_id', referencedColumnName: 'id', foreignKeyConstraintName: 'fk_posts_author')]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class JoinColumn
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly string $referencedColumnName = 'id',
        public readonly ?string $foreignKeyConstraintName = null,
    ) {}
}
