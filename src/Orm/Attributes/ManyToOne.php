<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Owning side of a many-to-one relation; the foreign key lives in this table.
 * `onDelete` / `onUpdate`: CASCADE | SET NULL | RESTRICT | NO ACTION | SET DEFAULT ('DEFAULT' is accepted for
 * SET DEFAULT); `createForeignKeyConstraints: false` keeps the column but creates no FOREIGN KEY.
 *
 *   #[ManyToOne(User::class, inverseSide: 'posts', onDelete: 'CASCADE')]
 *   #[JoinColumn(name: 'user_id')]
 *   public User $user;
 *
 * Relation options (TypeORM's RelationOptions):
 *   cascade            true | ['insert', 'update', 'remove', 'soft-remove', 'recover']
 *   eager              loaded automatically by find*() / findOne*() (not by the QueryBuilder)
 *   lazy               PHP 8.4+: the property holds a native lazy ghost loaded on first access
 *   orphanedRowAction  what save() does with rows removed from the inverse OneToMany collection:
 *                      'nullify' (default: FK set to NULL), 'delete', 'soft-delete' or 'disable' (left alone)
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ManyToOne
{
    /**
     * @param class-string $target
     * @param bool|list<'insert'|'update'|'remove'|'soft-remove'|'recover'> $cascade
     * @param 'nullify'|'delete'|'soft-delete'|'disable'|null $orphanedRowAction
     */
    public function __construct(
        public readonly string $target,
        public readonly ?string $inverseSide = null,
        public readonly bool $nullable = true,
        public readonly ?string $onDelete = null,
        public readonly bool|array $cascade = false,
        public readonly bool $eager = false,
        public readonly bool $lazy = false,
        public readonly ?string $orphanedRowAction = null,
        public readonly ?string $onUpdate = null,
        public readonly bool $createForeignKeyConstraints = true,
    ) {}
}
