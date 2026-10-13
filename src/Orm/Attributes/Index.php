<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Table index (TypeORM's @Index), on the entity class (column or property names) or on a property.
 *
 *   #[Index(columns: ['email'], unique: true)]
 *   #[Index(columns: ['status', 'created_at'], name: 'idx_posts_status')]
 *   #[Index(columns: ['email'], unique: true, where: '"deleted_at" IS NULL')]  // partial index (PostgreSQL, SQLite)
 *
 *   #[Index] #[Column] public string $slug;                                  // on a property: that column
 *   #[Index('idx_users_slug', unique: true)] #[Column] public string $slug;  // a string first argument names it
 *
 * `where` is ignored on MySQL/MariaDB, which have no partial indexes.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class Index
{
    /** @var list<string> */
    public readonly array $columns;
    public readonly ?string $name;

    /** @param list<string>|string $columns column / property names, or (a string) the index name */
    public function __construct(
        array|string $columns = [],
        public readonly bool $unique = false,
        ?string $name = null,
        public readonly ?string $where = null,
    ) {
        $this->columns = is_array($columns) ? array_values($columns) : [];
        $this->name = $name ?? (is_string($columns) ? $columns : null);
    }
}
