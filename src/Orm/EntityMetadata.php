<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Nesto\Orm\Mapping\ColumnMetadata;
use Nesto\Orm\Mapping\RelationMetadata;

/**
 * Everything the ORM knows about one entity class: table, columns, primary key, relations,
 * timestamp / soft-delete columns and indexes. Obtain through MetadataRegistry::get(User::class).
 *
 *   $meta = MetadataRegistry::get(User::class);
 *   $meta->table;                 // "users"
 *   $meta->column('createdAt');   // ColumnMetadata (name "created_at", type "datetime")
 *   $meta->relation('posts');     // RelationMetadata (OneToMany → Post)
 */
final class EntityMetadata
{
    /**
     * @param class-string                     $class
     * @param array<string, ColumnMetadata>    $columns   keyed by column NAME (includes implicit FK columns)
     * @param array<string, RelationMetadata>  $relations keyed by property
     * @param list<array{columns: list<string>, unique: bool, name: ?string, where?: ?string}> $indexes
     * @param ?string                          $morphAlias #[MorphAlias] of the class (see MorphMap)
     * @param array<string, string>            $relationIds #[RelationId] properties: property => relation property
     * @param list<array{columns: list<string>, name: ?string}> $uniques  #[Unique] constraints (column names)
     * @param list<array{expression: string, name: ?string}>   $checks   #[Check] constraints
     * @param bool                             $synchronize #[Entity(synchronize)]: false keeps the table out of schema sync
     * @param ?string                          $version   #[VersionColumn] property
     * @param ?string                          $inheritanceRoot single table inheritance: the #[TableInheritance] root
     *                                                    class (the root itself included); null otherwise
     * @param ?string                          $discriminatorColumn single table inheritance: discriminator column name
     * @param ?string                          $discriminatorValue  this class's discriminator value
     * @param array<string, class-string>      $discriminatorMap    value => class for this class and its child entities
     * @param ?Mapping\TreeMetadata            $tree      #[Tree] entities
     * @param ?Mapping\ViewMetadata             $view      set for a #[ViewEntity] (read-only, no table)
     */
    public function __construct(
        public readonly string $class,
        public readonly string $table,
        public readonly array $columns,
        public readonly ColumnMetadata $primary,
        public readonly array $relations,
        public readonly array $indexes = [],
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly ?string $deletedAt = null,
        public readonly ?string $morphAlias = null,
        public readonly array $relationIds = [],
        public readonly array $uniques = [],
        public readonly array $checks = [],
        public readonly bool $synchronize = true,
        public readonly ?string $version = null,
        public readonly ?string $inheritanceRoot = null,
        public readonly ?string $discriminatorColumn = null,
        public readonly ?string $discriminatorValue = null,
        public readonly array $discriminatorMap = [],
        public readonly ?Mapping\TreeMetadata $tree = null,
        public readonly ?Mapping\ViewMetadata $view = null,
    ) {}

    /** A #[ViewEntity] (TypeORM's tableType "view"): mapped to a database view, read-only. */
    public function isView(): bool
    {
        return $this->view !== null;
    }

    /** Throws for view entities: they are read-only. */
    public function assertWritable(string $operation): void
    {
        if ($this->view !== null) {
            throw new OrmException("{$this->class} is a view entity (#[ViewEntity]) and cannot be written: $operation is not supported.");
        }
    }

    /** @var array<string, ColumnMetadata>|null */
    private ?array $selectColumns = null;

    /** A #[ChildEntity] of a single table inheritance (its queries are limited to its discriminator values). */
    public function isChildEntity(): bool
    {
        return $this->inheritanceRoot !== null && $this->inheritanceRoot !== $this->class;
    }

    /**
     * Discriminator values a query on this entity is limited to: this child's value and its descendants'.
     * Null for regular entities and for an inheritance root (which sees every row, like TypeORM).
     *
     * @return list<string>|null
     */
    public function discriminatorValues(): ?array
    {
        return $this->isChildEntity() ? array_map('strval', array_keys($this->discriminatorMap)) : null;
    }

    /** @return list<EntityMetadata> metadata of the child entities below this one (single table inheritance) */
    public function descendantMetadatas(): array
    {
        $out = [];
        foreach ($this->discriminatorMap as $class) {
            if ($class !== $this->class) {
                $out[] = MetadataRegistry::get($class);
            }
        }

        return $out;
    }

    /**
     * Columns an entity query selects: this entity's columns plus, for a single table inheritance parent, the
     * columns only its children have (a row is hydrated as the class its discriminator names).
     *
     * @return array<string, ColumnMetadata> keyed by column name
     */
    public function selectColumns(): array
    {
        if ($this->selectColumns === null) {
            $columns = $this->columns;
            foreach ($this->descendantMetadatas() as $child) {
                $columns += $child->columns;
            }
            $this->selectColumns = $columns;
        }

        return $this->selectColumns;
    }

    /** The #[TreeParent] relation of a #[Tree] entity. */
    public function treeParentRelation(): ?RelationMetadata
    {
        return $this->tree !== null ? $this->relations[$this->tree->parentProperty] : null;
    }

    /** The #[VersionColumn], if any. */
    public function versionColumn(): ?ColumnMetadata
    {
        return $this->version !== null ? $this->column($this->version) : null;
    }

    /** Column by property name OR column name. */
    public function column(string $propertyOrName): ?ColumnMetadata
    {
        foreach ($this->columns as $column) {
            if ($column->property === $propertyOrName) {
                return $column;
            }
        }

        return $this->columns[$propertyOrName] ?? null;
    }

    /** Resolve a property name (or column name, or relation property) to the column name used in SQL. */
    public function columnName(string $propertyOrName): string
    {
        $column = $this->column($propertyOrName);
        if ($column !== null) {
            return $column->name;
        }
        $relation = $this->relations[$propertyOrName] ?? null;
        if ($relation?->joinColumn !== null) {
            return $relation->joinColumn;
        }
        foreach ($this->descendantMetadatas() as $child) {
            // a single table inheritance parent can be queried by its children's columns
            if (($childColumn = $child->column($propertyOrName)) !== null) {
                return $childColumn->name;
            }
            if (($joinColumn = $child->relation($propertyOrName)?->joinColumn) !== null) {
                return $joinColumn;
            }
        }

        return $propertyOrName;
    }

    /**
     * Columns of the #[Embedded] property (or nested embedded) at a dotted path ("address", "address.geo");
     * empty when the path is not embedded.
     *
     * @return list<ColumnMetadata>
     */
    public function embeddedColumns(string $path): array
    {
        $prefix = $path . '.';
        $columns = [];
        foreach ($this->selectColumns() as $column) { // a single table inheritance parent: its children's too
            if ($column->embedded !== [] && str_starts_with((string) $column->property, $prefix)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    public function relation(string $property): ?RelationMetadata
    {
        return $this->relations[$property] ?? null;
    }

    /** @return list<ColumnMetadata> columns backed by a property */
    public function propertyColumns(): array
    {
        return array_values(array_filter($this->columns, fn (ColumnMetadata $c) => $c->property !== null));
    }

    public function hasSoftDelete(): bool
    {
        return $this->deletedAt !== null;
    }

    public function deletedAtColumn(): ?ColumnMetadata
    {
        return $this->deletedAt !== null ? $this->column($this->deletedAt) : null;
    }

    public function primaryValue(object $entity): mixed
    {
        $prop = $this->primary->property;
        if ($prop === null) {
            return null;
        }
        $ref = new \ReflectionProperty($entity, $prop);

        return $ref->isInitialized($entity) ? $entity->{$prop} : null;
    }

    public function setPrimaryValue(object $entity, mixed $value): void
    {
        $entity->{$this->primary->property} = Mapping\TypeConverter::toPhp($this->primary, $value);
    }

    public function isInitialized(object $entity, string $property): bool
    {
        if (str_contains($property, '.')) {
            $column = $this->column($property);

            return $column !== null && Mapping\PropertyAccess::isInitialized($entity, $column);
        }

        return (new \ReflectionProperty($entity, $property))->isInitialized($entity);
    }
}
