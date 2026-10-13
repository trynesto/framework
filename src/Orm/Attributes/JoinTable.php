<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Join table of an owning ManyToMany. Defaults: name `{table}_{targetTable}`, columns `{entity}_id` / `{target}_id`.
 *
 *   #[JoinTable(name: 'post_tags', joinColumn: 'post_id', inverseJoinColumn: 'tag_id')]
 *   #[JoinTable(
 *       name: 'post_tags',
 *       joinColumn: ['name' => 'post_id', 'foreignKeyConstraintName' => 'fk_post_tags_post'],
 *       inverseJoinColumn: ['name' => 'tag_id', 'foreignKeyConstraintName' => 'fk_post_tags_tag'],
 *   )]
 *
 * Both join columns reference the primary keys. `synchronize: false` leaves the table out of schema
 * synchronization and migration generation (TypeORM's JoinTable `synchronize` option).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class JoinTable
{
    /**
     * @param string|array{name?: string, foreignKeyConstraintName?: string}|null $joinColumn
     * @param string|array{name?: string, foreignKeyConstraintName?: string}|null $inverseJoinColumn
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly string|array|null $joinColumn = null,
        public readonly string|array|null $inverseJoinColumn = null,
        public readonly bool $synchronize = true,
    ) {}

    public function joinColumnName(): ?string
    {
        return is_array($this->joinColumn) ? ($this->joinColumn['name'] ?? null) : $this->joinColumn;
    }

    public function inverseJoinColumnName(): ?string
    {
        return is_array($this->inverseJoinColumn) ? ($this->inverseJoinColumn['name'] ?? null) : $this->inverseJoinColumn;
    }

    public function joinForeignKeyName(): ?string
    {
        return is_array($this->joinColumn) ? ($this->joinColumn['foreignKeyConstraintName'] ?? null) : null;
    }

    public function inverseForeignKeyName(): ?string
    {
        return is_array($this->inverseJoinColumn) ? ($this->inverseJoinColumn['foreignKeyConstraintName'] ?? null) : null;
    }
}
