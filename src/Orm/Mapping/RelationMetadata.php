<?php

declare(strict_types=1);

namespace Nesto\Orm\Mapping;

/** A relation property: which entity it points to and how the tables are linked. */
final class RelationMetadata
{
    /**
     * @param class-string $target            related entity ('' for MorphTo, whose target depends on the row)
     * @param ?string      $joinColumn        FK column in this table (ManyToOne / owning OneToOne)
     * @param ?string      $joinTable         join table (ManyToMany, MorphToMany, MorphedByMany)
     * @param ?string      $joinTableColumn   join-table column referencing THIS entity
     * @param ?string      $inverseJoinColumn join-table column referencing the TARGET entity
     * @param ?string      $morphName         polymorphic name ("commentable")
     * @param ?string      $morphTypeColumn   column storing the type alias ("commentable_type"): in this table
     *                                        (MorphTo), the target table (MorphOne/MorphMany) or the pivot table
     * @param ?string      $morphIdColumn     column storing the id ("commentable_id"): in this table (MorphTo)
     *                                        or the target table (MorphOne/MorphMany)
     * @param ?string      $morphClass        class whose alias is written to the type column (MorphOne/Many/ToMany:
     *                                        this entity; MorphedByMany: the target)
     * @param list<class-string> $morphTypes  MorphTo: the entity classes it may point to (optional)
     * @param ?string      $morphIdType       MorphTo: column type of the id column (null: not declared — synchronize
     *                                        infers it from the MorphOne/MorphMany parents, else bigint)
     * @param list<class-string> $through     intermediate entities of a through relation
     * @param array<string, mixed> $throughKeys key overrides of a through relation (see the attributes)
     * @param ?string      $localKey          MorphOne/MorphMany: key on this entity stored in the target's id column
     * @param list<string> $cascade           cascaded operations: insert, update, remove, soft-remove, recover
     * @param bool         $eager             loaded by find*() automatically
     * @param bool         $lazy              holds a lazy ghost (to-one) / LazyCollection (to-many) until accessed
     * @param ?string      $orphanedRowAction ManyToOne: nullify (default) | delete | soft-delete | disable
     * @param ?string      $onUpdate          ON UPDATE action of the foreign key (to-one owning side, ManyToMany join table)
     * @param bool         $createForeignKeyConstraints false: the FK column / join table gets no FOREIGN KEY
     * @param ?string      $foreignKeyConstraintName #[JoinColumn(foreignKeyConstraintName)]
     * @param bool         $joinTableSynchronize #[JoinTable(synchronize)]
     * @param ?string      $joinForeignKeyName / $inverseJoinForeignKeyName join-table FK names (#[JoinTable] arrays)
     */
    public function __construct(
        public readonly string $property,
        public readonly RelationType $type,
        public readonly string $target,
        public readonly ?string $inverseSide = null,
        public readonly bool $owning = false,
        public readonly bool $nullable = true,
        public readonly ?string $onDelete = null,
        public readonly ?string $joinColumn = null,
        public readonly string $referencedColumn = 'id',
        public readonly ?string $joinTable = null,
        public readonly ?string $joinTableColumn = null,
        public readonly ?string $inverseJoinColumn = null,
        public readonly ?string $morphName = null,
        public readonly ?string $morphTypeColumn = null,
        public readonly ?string $morphIdColumn = null,
        public readonly ?string $morphClass = null,
        public readonly array $morphTypes = [],
        public readonly ?string $morphIdType = null,
        public readonly array $through = [],
        public readonly array $throughKeys = [],
        public readonly ?string $localKey = null,
        public readonly array $cascade = [],
        public readonly bool $eager = false,
        public readonly bool $lazy = false,
        public readonly ?string $orphanedRowAction = null,
        public readonly ?string $onUpdate = null,
        public readonly bool $createForeignKeyConstraints = true,
        public readonly ?string $foreignKeyConstraintName = null,
        public readonly bool $joinTableSynchronize = true,
        public readonly ?string $joinForeignKeyName = null,
        public readonly ?string $inverseJoinForeignKeyName = null,
    ) {}

    public const CASCADE_OPERATIONS = ['insert', 'update', 'remove', 'soft-remove', 'recover'];

    /** Does save()/remove()/softRemove()/recover() cascade $operation ("insert", "update", ...) through this relation? */
    public function cascades(string $operation): bool
    {
        return in_array($operation, $this->cascade, true);
    }

    public function isToMany(): bool
    {
        return $this->type->isToMany();
    }

    /** Owning side of a to-one relation: this table holds the FK. */
    public function holdsForeignKey(): bool
    {
        return $this->joinColumn !== null;
    }

    public function isMorph(): bool
    {
        return $this->type->isMorph();
    }

    public function isThrough(): bool
    {
        return $this->type->isThrough();
    }

    /** Through relations are computed from other tables: save() never writes them. */
    public function isReadOnly(): bool
    {
        return $this->type->isThrough();
    }
}
