<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * One-to-one relation. The side carrying #[JoinColumn] owns the foreign key (unique).
 * `onDelete` / `onUpdate` / `createForeignKeyConstraints` as on ManyToOne.
 *
 *   #[OneToOne(Profile::class, inverseSide: 'user')] #[JoinColumn] public ?Profile $profile = null;  // owning
 *   #[OneToOne(User::class, inverseSide: 'profile')] public ?User $user = null;                      // inverse
 *
 * Relation options (TypeORM's RelationOptions):
 *   cascade  true | ['insert', 'update', 'remove', 'soft-remove', 'recover']: save()/remove()/softRemove()/
 *            recover() also persist / remove the related entity
 *   eager    loaded automatically by find*() / findOne*() (not by the QueryBuilder)
 *   lazy     owning side only, PHP 8.4+: the property holds a native lazy ghost loaded on first access
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class OneToOne
{
    /**
     * @param class-string $target
     * @param bool|list<'insert'|'update'|'remove'|'soft-remove'|'recover'> $cascade
     */
    public function __construct(
        public readonly string $target,
        public readonly ?string $inverseSide = null,
        public readonly bool $nullable = true,
        public readonly ?string $onDelete = null,
        public readonly bool|array $cascade = false,
        public readonly bool $eager = false,
        public readonly bool $lazy = false,
        public readonly ?string $onUpdate = null,
        public readonly bool $createForeignKeyConstraints = true,
    ) {}
}
