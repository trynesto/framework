<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Nesto\Orm\Attributes\BelongsToThrough;
use Nesto\Orm\Attributes\Check;
use Nesto\Orm\Attributes\ChildEntity;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\CreateDateColumn;
use Nesto\Orm\Attributes\DeleteDateColumn;
use Nesto\Orm\Attributes\Embedded;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\HasManyThrough;
use Nesto\Orm\Attributes\HasOneThrough;
use Nesto\Orm\Attributes\Index;
use Nesto\Orm\Attributes\JoinColumn;
use Nesto\Orm\Attributes\JoinTable;
use Nesto\Orm\Attributes\ManyToMany;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\MorphedByMany;
use Nesto\Orm\Attributes\MorphMany;
use Nesto\Orm\Attributes\MorphOne;
use Nesto\Orm\Attributes\MorphTo;
use Nesto\Orm\Attributes\MorphToMany;
use Nesto\Orm\Attributes\OneToMany;
use Nesto\Orm\Attributes\OneToOne;
use Nesto\Orm\Attributes\PrimaryColumn;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;
use Nesto\Orm\Attributes\RelationId;
use Nesto\Orm\Attributes\Tree;
use Nesto\Orm\Attributes\TreeChildren;
use Nesto\Orm\Attributes\TreeLevelColumn;
use Nesto\Orm\Attributes\TreeParent;
use Nesto\Orm\Attributes\Unique;
use Nesto\Orm\Attributes\UpdateDateColumn;
use Nesto\Orm\Mapping\ColumnFactory;
use Nesto\Orm\Attributes\VersionColumn;
use Nesto\Orm\Mapping\ColumnMetadata;
use Nesto\Orm\Mapping\Inheritance;
use Nesto\Orm\Mapping\Naming;
use Nesto\Orm\Mapping\RelationMetadata;
use Nesto\Orm\Mapping\RelationType;
use Nesto\Orm\Mapping\TreeMetadata;
use Nesto\Orm\Mapping\TypeConverter;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Builds and caches EntityMetadata from the attributes on an entity class.
 *
 *   $meta = MetadataRegistry::get(Post::class);
 *   MetadataRegistry::clear(); // tests
 */
final class MetadataRegistry
{
    /** @var array<class-string, EntityMetadata> */
    private static array $cache = [];

    /** @param class-string $class */
    public static function get(string $class): EntityMetadata
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }
        $meta = self::$cache[$class] = self::build($class);
        if ($meta->isChildEntity()) {
            // a parent built before this child was declared does not know it yet: rebuild it on next use
            for ($parent = get_parent_class($class); $parent !== false; $parent = get_parent_class($parent)) {
                if (isset(self::$cache[$parent]) && ! isset(self::$cache[$parent]->discriminatorMap[(string) $meta->discriminatorValue])) {
                    unset(self::$cache[$parent]);
                }
            }
        }

        return $meta;
    }

    public static function has(string $class): bool
    {
        if (isset(self::$cache[$class])) {
            return true;
        }
        if (! class_exists($class)) {
            return false;
        }
        $ref = new ReflectionClass($class);

        return $ref->getAttributes(Entity::class) !== [] || $ref->getAttributes(ChildEntity::class) !== [] || $ref->getAttributes(Attributes\ViewEntity::class) !== [];
    }

    public static function clear(): void
    {
        self::$cache = [];
        Mapping\ThroughPath::clear();
        EagerRelations::clear();
        Events\EntityListeners::clear();
    }

    private static function build(string $class): EntityMetadata
    {
        if (! class_exists($class)) {
            throw new OrmException("Entity class \"$class\" does not exist.");
        }
        $ref = new ReflectionClass($class);
        $view = ($ref->getAttributes(Attributes\ViewEntity::class)[0] ?? null)?->newInstance();
        if ($view !== null) {
            return Mapping\ViewMetadataBuilder::build($ref, $view);
        }
        $entity = ($ref->getAttributes(Entity::class)[0] ?? null)?->newInstance();
        $isChild = $ref->getAttributes(ChildEntity::class) !== [];
        if ($entity !== null && $isChild) {
            throw new OrmException("$class has both #[Entity] and #[ChildEntity]: a child entity lives in its parent's table, keep only #[ChildEntity].");
        }
        if ($entity === null && ! $isChild) {
            throw new OrmException("Class \"$class\" is not an entity: add #[Entity] to it.");
        }
        // single table inheritance: children use the root's table and its discriminator column
        $root = Inheritance::rootOf($class);
        if ($isChild) {
            $entity = (new ReflectionClass((string) $root))->getAttributes(Entity::class)[0]->newInstance();
            $table = $entity->name ?? Naming::tableFor((string) $root);
        } else {
            $table = $entity->name ?? Naming::tableFor($class);
        }
        /** @var list<ReflectionClass<object>> $chain the class and its parents, top-most parent first */
        $chain = [];
        for ($current = $ref; $current !== false; $current = $current->getParentClass()) {
            array_unshift($chain, $current);
        }
        $treeAttr = ($ref->getAttributes(Tree::class)[0] ?? null)?->newInstance();
        [$treeParentProp, $treeChildrenProp] = self::treeProperties($ref);

        /** @var array<string, ColumnMetadata> $columns */
        $columns = [];
        /** @var array<string, RelationMetadata> $relations */
        $relations = [];
        $primary = null;
        $createdAt = $updatedAt = $deletedAt = $version = $treeLevel = null;
        /** @var array<string, string> $relationIds */
        $relationIds = [];
        /** @var list<array{0: string, 1: object}> property-level #[Index] / #[Unique] */
        $propertyConstraints = [];

        foreach (self::properties($ref, $chain) as $prop) {
            $name = $prop->getName();
            $phpType = self::phpType($prop);
            $nullableType = $prop->getType()?->allowsNull() ?? true;
            foreach ([Index::class, Unique::class] as $constraintClass) {
                foreach ($prop->getAttributes($constraintClass) as $constraintAttr) {
                    $propertyConstraints[] = [$name, $constraintAttr->newInstance()];
                }
            }

            if ($attr = self::attr($prop, PrimaryGeneratedColumn::class)) {
                $primary = ColumnFactory::primaryGenerated($class, $prop, $attr);
                $columns[$primary->name] = $primary;
                continue;
            }
            if ($attr = self::attr($prop, Embedded::class)) {
                foreach (ColumnFactory::embedded($class, $prop, $attr) as $column) {
                    if (isset($columns[$column->name])) {
                        throw new OrmException("$class: embedded column \"{$column->name}\" ({$column->property}) clashes with another column; give the #[Embedded] a prefix.");
                    }
                    $columns[$column->name] = $column;
                }
                continue;
            }
            if ($attr = self::attr($prop, PrimaryColumn::class)) {
                [$inferred, $enum] = TypeConverter::inferType($phpType);
                $primary = new ColumnMetadata($name, $attr->name ?? Naming::snake($name), $attr->type ?? $inferred, primary: true, enumClass: $enum, phpType: $phpType);
                $columns[$primary->name] = $primary;
                continue;
            }
            foreach ([CreateDateColumn::class => 'created', UpdateDateColumn::class => 'updated', DeleteDateColumn::class => 'deleted'] as $attrClass => $special) {
                if ($attr = self::attr($prop, $attrClass)) {
                    $column = new ColumnMetadata($name, $attr->name ?? Naming::snake($name), 'datetime', nullable: true, phpType: $phpType, special: $special);
                    $columns[$column->name] = $column;
                    match ($special) {
                        'created' => $createdAt = $name,
                        'updated' => $updatedAt = $name,
                        'deleted' => $deletedAt = $name,
                    };
                    continue 2;
                }
            }
            if ($attr = self::attr($prop, VersionColumn::class)) {
                $column = new ColumnMetadata($name, $attr->name ?? Naming::snake($name), 'int', phpType: $phpType, special: 'version');
                $columns[$column->name] = $column;
                $version = $name;
                continue;
            }
            if ($attr = self::attr($prop, Column::class)) {
                $column = ColumnFactory::column($class, $prop, $attr, $name, $attr->name ?? Naming::snake($name));
                $columns[$column->name] = $column;
                if ($attr->primary) {
                    $primary ??= $column;
                }
                continue;
            }
            if ($attr = self::attr($prop, TreeLevelColumn::class)) {
                $column = new ColumnMetadata($name, $attr->name ?? Naming::snake($name), 'int', default: 0, hasDefault: true, phpType: $phpType, special: 'treeLevel');
                $columns[$column->name] = $column;
                $treeLevel = $name;
                continue;
            }
            if ($attr = self::attr($prop, RelationId::class)) {
                $relationIds[$name] = $attr->relation;
                continue;
            }
            if ($attr = self::attr($prop, TreeParent::class)) {
                $join = self::attr($prop, JoinColumn::class);
                $relations[$name] = new RelationMetadata(
                    $name,
                    RelationType::ManyToOne,
                    $class,
                    $treeChildrenProp,
                    owning: true,
                    nullable: true,
                    onDelete: $attr->onDelete,
                    joinColumn: $join?->name ?? Naming::snake($name) . '_id',
                    referencedColumn: $join?->referencedColumnName ?? 'id',
                    eager: $attr->eager,
                    onUpdate: $attr->onUpdate,
                    foreignKeyConstraintName: $join?->foreignKeyConstraintName,
                );
                continue;
            }
            if ($attr = self::attr($prop, TreeChildren::class)) {
                if ($treeParentProp === null) {
                    throw new OrmException("#[TreeChildren] $class::\$$name needs a #[TreeParent] property on $class.");
                }
                $relations[$name] = new RelationMetadata(
                    $name,
                    RelationType::OneToMany,
                    $class,
                    $treeParentProp,
                    owning: false,
                    cascade: self::cascade($class, $name, $attr->cascade),
                    eager: $attr->eager,
                );
                continue;
            }
            if ($attr = self::attr($prop, ManyToOne::class)) {
                $join = self::attr($prop, JoinColumn::class);
                $relations[$name] = new RelationMetadata(
                    $name,
                    RelationType::ManyToOne,
                    $attr->target,
                    $attr->inverseSide,
                    owning: true,
                    nullable: $attr->nullable && $nullableType,
                    onDelete: $attr->onDelete,
                    joinColumn: $join?->name ?? Naming::snake($name) . '_id',
                    referencedColumn: $join?->referencedColumnName ?? 'id',
                    cascade: self::cascade($class, $name, $attr->cascade),
                    eager: $attr->eager,
                    lazy: self::lazy($class, $prop, $attr->lazy, false),
                    orphanedRowAction: self::orphanedRowAction($class, $name, $attr->orphanedRowAction),
                    onUpdate: $attr->onUpdate,
                    createForeignKeyConstraints: $attr->createForeignKeyConstraints,
                    foreignKeyConstraintName: $join?->foreignKeyConstraintName,
                );
                continue;
            }
            if ($attr = self::attr($prop, OneToOne::class)) {
                $join = self::attr($prop, JoinColumn::class);
                $relations[$name] = new RelationMetadata(
                    $name,
                    RelationType::OneToOne,
                    $attr->target,
                    $attr->inverseSide,
                    owning: $join !== null,
                    nullable: $attr->nullable && $nullableType,
                    onDelete: $attr->onDelete,
                    joinColumn: $join !== null ? ($join->name ?? Naming::snake($name) . '_id') : null,
                    referencedColumn: $join?->referencedColumnName ?? 'id',
                    cascade: self::cascade($class, $name, $attr->cascade),
                    eager: $attr->eager,
                    lazy: self::lazy($class, $prop, $attr->lazy, false, owning: $join !== null),
                    onUpdate: $attr->onUpdate,
                    createForeignKeyConstraints: $attr->createForeignKeyConstraints,
                    foreignKeyConstraintName: $join?->foreignKeyConstraintName,
                );
                continue;
            }
            if ($attr = self::attr($prop, OneToMany::class)) {
                $relations[$name] = new RelationMetadata(
                    $name,
                    RelationType::OneToMany,
                    $attr->target,
                    $attr->inverseSide,
                    owning: false,
                    cascade: self::cascade($class, $name, $attr->cascade),
                    eager: $attr->eager,
                    lazy: self::lazy($class, $prop, $attr->lazy, true),
                );
                continue;
            }
            if ($attr = self::attr($prop, ManyToMany::class)) {
                $joinTable = self::attr($prop, JoinTable::class);
                $targetShort = Naming::snake(Naming::shortName($attr->target));
                $ownShort = Naming::snake(Naming::shortName($class));
                // self-referencing (User ↔ User "friends"): both default columns would be "user_id"
                $defaultInverse = $targetShort === $ownShort ? 'related_' . $targetShort . '_id' : $targetShort . '_id';
                $relations[$name] = new RelationMetadata(
                    $name,
                    RelationType::ManyToMany,
                    $attr->target,
                    $attr->inverseSide,
                    owning: $joinTable !== null,
                    onDelete: $attr->onDelete,
                    joinTable: $joinTable !== null ? ($joinTable->name ?? $table . '_' . Naming::plural($targetShort)) : null,
                    joinTableColumn: $joinTable !== null ? ($joinTable->joinColumnName() ?? $ownShort . '_id') : null,
                    inverseJoinColumn: $joinTable !== null ? ($joinTable->inverseJoinColumnName() ?? $defaultInverse) : null,
                    cascade: self::cascade($class, $name, $attr->cascade),
                    eager: $attr->eager,
                    lazy: self::lazy($class, $prop, $attr->lazy, true),
                    onUpdate: $attr->onUpdate,
                    createForeignKeyConstraints: $attr->createForeignKeyConstraints,
                    joinTableSynchronize: $joinTable?->synchronize ?? true,
                    joinForeignKeyName: $joinTable?->joinForeignKeyName(),
                    inverseJoinForeignKeyName: $joinTable?->inverseForeignKeyName(),
                );
                continue;
            }
            if ($relation = self::morphOrThroughRelation($class, $prop, $nullableType)) {
                $relations[$name] = $relation;
                continue;
            }
        }

        if ($primary === null) {
            throw new OrmException("Entity \"$class\" has no primary column: add #[PrimaryGeneratedColumn] or #[PrimaryColumn].");
        }

        $indexes = [];

        // MorphTo: a type column and an id column (implicit unless declared with #[Column]) plus a composite index.
        foreach ($relations as $relation) {
            if ($relation->type !== RelationType::MorphTo) {
                continue;
            }
            foreach (['type' => $relation->morphTypeColumn, 'id' => $relation->morphIdColumn] as $part => $columnName) {
                $existing = $columns[$columnName] ?? null;
                $columns[$columnName] = $existing !== null
                    ? $existing->linkedTo($relation->property, $part)
                    : new ColumnMetadata(
                        null,
                        $columnName,
                        $part === 'type' ? 'string' : ($relation->morphIdType ?? 'bigint'),
                        nullable: $relation->nullable,
                        length: $part === 'type' || $relation->morphIdType === 'string' ? 255 : null,
                        relation: $relation->property,
                        morph: $part,
                    );
            }
            $indexes[] = ['columns' => [(string) $relation->morphTypeColumn, (string) $relation->morphIdColumn], 'unique' => false, 'name' => null, 'where' => null];
        }

        // Implicit foreign-key columns for owning to-one relations that have no explicit #[Column] with the same name.
        foreach ($relations as $relation) {
            if ($relation->joinColumn === null) {
                continue;
            }
            if (isset($columns[$relation->joinColumn])) {
                $columns[$relation->joinColumn] = $columns[$relation->joinColumn]->linkedTo($relation->property);
                continue;
            }
            // Only the target's primary key type is needed here; building the target's full metadata would
            // recurse forever when two entities reference each other (A ManyToOne B, B ManyToOne A).
            $targetType = $relation->target === $class ? $primary->type : self::primaryType($relation->target);
            $columns[$relation->joinColumn] = new ColumnMetadata(
                null,
                $relation->joinColumn,
                $targetType,
                nullable: $relation->nullable,
                relation: $relation->property,
            );
        }

        $uniques = [];
        $checks = [];
        $columnOf = function (string $propertyOrColumn) use ($columns, $relations): string {
            foreach ($columns as $column) {
                if ($column->property === $propertyOrColumn) {
                    return $column->name;
                }
            }

            return $relations[$propertyOrColumn]->joinColumn ?? $propertyOrColumn;
        };
        // class-level constraints of parent classes apply too (concrete table inheritance, like TypeORM)
        foreach (self::classAttributes($chain, Index::class) as $index) {
            $indexes[] = ['columns' => $index->columns, 'unique' => $index->unique, 'name' => $index->name, 'where' => $index->where];
        }
        foreach (self::classAttributes($chain, Unique::class) as $unique) {
            $uniques[] = ['columns' => array_map($columnOf, $unique->columns), 'name' => $unique->name];
        }
        foreach ($propertyConstraints as [$property, $constraint]) {
            $columnNames = $constraint->columns !== [] ? array_map($columnOf, $constraint->columns) : [$columnOf($property)];
            if ($constraint instanceof Unique) {
                $uniques[] = ['columns' => $columnNames, 'name' => $constraint->name];
            } else {
                $indexes[] = ['columns' => $columnNames, 'unique' => $constraint->unique, 'name' => $constraint->name, 'where' => $constraint->where];
            }
        }
        foreach (self::classAttributes($chain, Check::class) as $check) {
            $checks[] = ['expression' => $check->expression, 'name' => $check->name];
        }

        foreach ($relationIds as $property => $relationName) {
            if (! isset($relations[$relationName])) {
                throw new OrmException("#[RelationId('$relationName')] on $class::\$$property: $class has no relation \"$relationName\".");
            }
            if ($relations[$relationName]->isMorph() || $relations[$relationName]->isThrough()) {
                throw new OrmException("#[RelationId('$relationName')] on $class::\$$property: only ManyToOne, OneToOne, OneToMany and ManyToMany relations are supported.");
            }
        }

        $morphAlias = MorphMap::registerClass($class);

        $discriminatorColumn = $discriminatorValue = null;
        $discriminatorMap = [];
        if ($root !== null) {
            [$discriminatorColumn, $type, $length] = Inheritance::column($root);
            // the discriminator is a column without property (unless the entity maps it itself)
            $columns[$discriminatorColumn] ??= new ColumnMetadata(null, $discriminatorColumn, $type, length: $length, special: 'discriminator');
            $discriminatorValue = Inheritance::discriminatorValue($class);
            $discriminatorMap = Inheritance::discriminatorMap($class);
        }
        $tree = $treeAttr !== null ? self::tree($class, $table, $treeAttr, $treeParentProp, $treeChildrenProp, $treeLevel, $primary, $columns) : null;

        return new EntityMetadata(
            $class, $table, $columns, $primary, $relations, $indexes, $createdAt, $updatedAt, $deletedAt, $morphAlias,
            relationIds: $relationIds, uniques: $uniques, checks: $checks, synchronize: $entity->synchronize, version: $version,
            inheritanceRoot: $root, discriminatorColumn: $discriminatorColumn, discriminatorValue: $discriminatorValue,
            discriminatorMap: $discriminatorMap, tree: $tree,
        );
    }

    /**
     * Public instance properties, those of the top-most parent class first (TypeORM orders inherited columns
     * before the class's own).
     *
     * @param list<ReflectionClass<object>> $chain
     * @return list<ReflectionProperty>
     */
    private static function properties(ReflectionClass $ref, array $chain): array
    {
        $byClass = [];
        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if (! $prop->isStatic()) {
                $byClass[$prop->getDeclaringClass()->getName()][] = $prop;
            }
        }
        $out = [];
        foreach ($chain as $class) {
            array_push($out, ...($byClass[$class->getName()] ?? []));
        }

        return $out;
    }

    /**
     * Instances of a class-level attribute on the class and its parents (top-most first).
     *
     * @template T of object
     * @param list<ReflectionClass<object>> $chain
     * @param class-string<T> $attribute
     * @return list<T>
     */
    private static function classAttributes(array $chain, string $attribute): array
    {
        $out = [];
        foreach ($chain as $class) {
            foreach ($class->getAttributes($attribute) as $attr) {
                $out[] = $attr->newInstance();
            }
        }

        return $out;
    }

    /** @return array{0: ?string, 1: ?string} the #[TreeParent] and #[TreeChildren] property names */
    private static function treeProperties(ReflectionClass $ref): array
    {
        $parent = $children = null;
        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->getAttributes(TreeParent::class) !== []) {
                $parent = $prop->getName();
            }
            if ($prop->getAttributes(TreeChildren::class) !== []) {
                $children = $prop->getName();
            }
        }

        return [$parent, $children];
    }

    /**
     * The #[Tree] metadata; adds the bookkeeping columns of nested sets (nsleft / nsright) and materialized paths
     * (mpath) — table columns without property, like TypeORM's.
     *
     * @param array<string, ColumnMetadata> $columns
     */
    private static function tree(string $class, string $table, Tree $attr, ?string $parent, ?string $children, ?string $level, ColumnMetadata $primary, array &$columns): TreeMetadata
    {
        if ($parent === null) {
            throw new OrmException("#[Tree('{$attr->type}')] $class needs a #[TreeParent] property (e.g. `#[TreeParent] public ?" . Naming::shortName($class) . ' $parent = null;`).');
        }
        if ($attr->type === 'nested-set') {
            $columns[TreeMetadata::NESTED_SET_LEFT] = new ColumnMetadata(null, TreeMetadata::NESTED_SET_LEFT, 'int', default: 1, hasDefault: true, special: 'nestedSetLeft');
            $columns[TreeMetadata::NESTED_SET_RIGHT] = new ColumnMetadata(null, TreeMetadata::NESTED_SET_RIGHT, 'int', default: 2, hasDefault: true, special: 'nestedSetRight');
        }
        if ($attr->type === 'materialized-path') {
            $columns[TreeMetadata::MATERIALIZED_PATH] = new ColumnMetadata(null, TreeMetadata::MATERIALIZED_PATH, 'string', nullable: true, default: '', hasDefault: true, length: 255, special: 'materializedPath');
        }
        $closure = $attr->type === 'closure-table';

        return new TreeMetadata(
            $attr->type,
            $parent,
            $children,
            $level,
            closureTable: $closure ? (string) ($attr->options['closureTableName'] ?? $table . '_closure') : null,
            ancestorColumn: $closure ? (string) ($attr->options['ancestorColumnName'] ?? $primary->name . '_ancestor') : null,
            descendantColumn: $closure ? (string) ($attr->options['descendantColumnName'] ?? $primary->name . '_descendant') : null,
        );
    }

    /** Polymorphic and through relations (null when the property has none of their attributes). */
    private static function morphOrThroughRelation(string $class, ReflectionProperty $prop, bool $nullableType): ?RelationMetadata
    {
        $name = $prop->getName();
        if ($attr = self::attr($prop, MorphTo::class)) {
            $morphName = $attr->name ?? Naming::snake($name);

            return new RelationMetadata(
                $name,
                RelationType::MorphTo,
                '',
                owning: true,
                nullable: $attr->nullable && $nullableType,
                morphName: $morphName,
                morphTypeColumn: $attr->type ?? $morphName . '_type',
                morphIdColumn: $attr->id ?? $morphName . '_id',
                morphTypes: array_values($attr->types),
                morphIdType: $attr->idType ?? ($attr->types !== [] ? self::morphIdType($attr->types) : null),
                eager: $attr->eager,
            );
        }
        foreach ([MorphOne::class => RelationType::MorphOne, MorphMany::class => RelationType::MorphMany] as $attrClass => $type) {
            if ($attr = self::attr($prop, $attrClass)) {
                [$typeColumn, $idColumn] = self::morphToColumns($attr->target, $attr->name);

                return new RelationMetadata(
                    $name,
                    $type,
                    $attr->target,
                    morphName: $attr->name,
                    morphTypeColumn: $attr->type ?? $typeColumn,
                    morphIdColumn: $attr->id ?? $idColumn,
                    morphClass: $class,
                    localKey: $attr->localKey,
                    eager: $attr->eager,
                );
            }
        }
        if ($attr = self::attr($prop, MorphToMany::class)) {
            return new RelationMetadata(
                $name,
                RelationType::MorphToMany,
                $attr->target,
                owning: true,
                joinTable: $attr->table ?? Naming::plural($attr->name),
                joinTableColumn: $attr->foreignPivotKey ?? $attr->name . '_id',
                inverseJoinColumn: $attr->relatedPivotKey ?? Naming::snake(Naming::shortName($attr->target)) . '_id',
                morphName: $attr->name,
                morphTypeColumn: $attr->type ?? $attr->name . '_type',
                morphClass: $class,
                eager: $attr->eager,
            );
        }
        if ($attr = self::attr($prop, MorphedByMany::class)) {
            return new RelationMetadata(
                $name,
                RelationType::MorphedByMany,
                $attr->target,
                owning: true,
                joinTable: $attr->table ?? Naming::plural($attr->name),
                joinTableColumn: $attr->foreignPivotKey ?? Naming::snake(Naming::shortName($class)) . '_id',
                inverseJoinColumn: $attr->relatedPivotKey ?? $attr->name . '_id',
                morphName: $attr->name,
                morphTypeColumn: $attr->type ?? $attr->name . '_type',
                morphClass: $attr->target,
                eager: $attr->eager,
            );
        }
        foreach ([HasManyThrough::class => RelationType::HasManyThrough, HasOneThrough::class => RelationType::HasOneThrough] as $attrClass => $type) {
            if ($attr = self::attr($prop, $attrClass)) {
                return new RelationMetadata(
                    $name,
                    $type,
                    $attr->target,
                    through: [$attr->through],
                    throughKeys: array_filter([
                        'firstKey' => $attr->firstKey,
                        'secondKey' => $attr->secondKey,
                        'localKey' => $attr->localKey,
                        'secondLocalKey' => $attr->secondLocalKey,
                    ], fn ($v) => $v !== null),
                    eager: $attr->eager,
                );
            }
        }
        if ($attr = self::attr($prop, BelongsToThrough::class)) {
            $through = array_values((array) $attr->through);
            if ($through === []) {
                throw new OrmException("BelongsToThrough $class::\$$name needs at least one intermediate entity in `through`.");
            }

            return new RelationMetadata(
                $name,
                RelationType::BelongsToThrough,
                $attr->target,
                through: $through,
                throughKeys: ['foreignKeys' => $attr->foreignKeys, 'localKeys' => $attr->localKeys],
                eager: $attr->eager,
            );
        }

        return null;
    }

    /**
     * Type / id column names of the #[MorphTo] named $morphName on $target (read from its attributes so
     * no metadata has to be built — entities may reference each other). Falls back to `{name}_type`/`{name}_id`.
     *
     * @return array{0: string, 1: string}
     */
    private static function morphToColumns(string $target, string $morphName): array
    {
        if (class_exists($target)) {
            foreach ((new ReflectionClass($target))->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
                $attr = self::attr($prop, MorphTo::class);
                if ($attr !== null && ($attr->name ?? Naming::snake($prop->getName())) === $morphName) {
                    return [$attr->type ?? $morphName . '_type', $attr->id ?? $morphName . '_id'];
                }
            }
        }

        return [$morphName . '_type', $morphName . '_id'];
    }

    /**
     * Column type for a morph id column holding primary keys of $classes: bigint for integer keys,
     * uuid for uuid keys, string when they differ (or are strings). bigint when nothing is known.
     *
     * @param list<class-string> $classes
     */
    public static function morphIdType(array $classes): string
    {
        $types = [];
        foreach ($classes as $class) {
            $types[self::keyType(self::primaryType($class))] = true;
        }

        return count($types) === 1 ? (string) array_key_first($types) : ($types === [] ? 'bigint' : 'string');
    }

    /** Column type of a column referencing a key of type $type. */
    public static function keyType(string $type): string
    {
        return match ($type) {
            'int', 'integer', 'bigint', 'smallint' => 'bigint',
            'uuid' => 'uuid',
            default => 'string',
        };
    }

    /** Column type of an entity's primary key, read from its attributes without building its metadata. */
    public static function primaryType(string $class): string
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class]->primary->type;
        }
        if (! class_exists($class)) {
            throw new OrmException("Entity class \"$class\" does not exist.");
        }
        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($attr = self::attr($prop, PrimaryGeneratedColumn::class)) {
                return $attr->columnType();
            }
            if ($attr = self::attr($prop, PrimaryColumn::class)) {
                return $attr->type ?? TypeConverter::inferType(self::phpType($prop))[0];
            }
        }

        return self::get($class)->primary->type; // throws the usual "no primary column" error
    }

    /**
     * `cascade: true` → every operation; a list is validated against RelationMetadata::CASCADE_OPERATIONS.
     *
     * @return list<string>
     */
    private static function cascade(string $class, string $property, bool|array $cascade): array
    {
        if ($cascade === true) {
            return RelationMetadata::CASCADE_OPERATIONS;
        }
        if ($cascade === false) {
            return [];
        }
        foreach ($cascade as $operation) {
            if (! in_array($operation, RelationMetadata::CASCADE_OPERATIONS, true)) {
                throw new OrmException("Unknown cascade \"$operation\" on $class::\$$property (expected true or a list of " . implode(', ', RelationMetadata::CASCADE_OPERATIONS) . ').');
            }
        }

        return array_values(array_unique($cascade));
    }

    private static function orphanedRowAction(string $class, string $property, ?string $action): ?string
    {
        if ($action !== null && ! in_array($action, ['nullify', 'delete', 'soft-delete', 'disable'], true)) {
            throw new OrmException("Unknown orphanedRowAction \"$action\" on $class::\$$property (expected nullify, delete, soft-delete or disable).");
        }

        return $action;
    }

    /**
     * Validates `lazy: true`: native lazy objects need PHP 8.4; a to-one relation must own the foreign key (an
     * inverse side cannot know whether a row exists without querying); a to-many property must accept both the
     * LazyCollection placeholder and the plain array that `relations` / save() put there (declare it `iterable`).
     */
    private static function lazy(string $class, ReflectionProperty $prop, bool $lazy, bool $toMany, bool $owning = true): bool
    {
        if (! $lazy) {
            return false;
        }
        $where = $class . '::$' . $prop->getName();
        if (PHP_VERSION_ID < 80400) {
            throw new OrmException("Lazy relations need PHP 8.4+ (native lazy objects): $where uses lazy: true on PHP " . PHP_VERSION . '. Remove lazy: true or load the relation with the `relations` find option.');
        }
        if (! $toMany && ! $owning) {
            throw new OrmException("$where: lazy: true is only supported on the owning side of a OneToOne (the one with #[JoinColumn]); put it there or load this side with `relations`.");
        }
        if ($toMany) {
            $type = $prop->getType();
            $names = [];
            foreach ($type instanceof \ReflectionUnionType ? $type->getTypes() : ($type === null ? [] : [$type]) as $t) {
                $names[] = $t instanceof ReflectionNamedType ? strtolower($t->getName()) : '';
            }
            $acceptsArray = $type === null || array_intersect($names, ['array', 'iterable', 'mixed']) !== [];
            $acceptsCollection = $type === null || array_intersect($names, ['iterable', 'mixed', 'object', strtolower(LazyCollection::class), 'traversable', 'countable']) !== [];
            if (! $acceptsArray || ! $acceptsCollection) {
                throw new OrmException("$where: a lazy to-many relation holds a " . LazyCollection::class . ' until it is loaded: declare the property as `iterable` (or `LazyCollection|array`).');
            }
        }

        return true;
    }

    private static function attr(ReflectionProperty $prop, string $class): ?object
    {
        return ($prop->getAttributes($class)[0] ?? null)?->newInstance();
    }

    private static function phpType(ReflectionProperty $prop): ?string
    {
        $type = $prop->getType();
        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }
        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $t) {
                if ($t instanceof ReflectionNamedType && $t->getName() !== 'null') {
                    return $t->getName();
                }
            }
        }

        return null;
    }
}
