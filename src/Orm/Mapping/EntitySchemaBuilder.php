<?php

declare(strict_types=1);

namespace Nesto\Orm\Mapping;

use Nesto\Orm\EntityMetadata;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\Migration\Blueprint;
use Nesto\Orm\Migration\ColumnDefinition;
use Nesto\Orm\Migration\ForeignKeyDefinition;
use Nesto\Orm\QueryBuilder;
use Nesto\Orm\Schema\Table;

/**
 * Adapter from entity metadata (attributes) to the neutral schema model: one Blueprint / Schema\Table per
 * entity table, plus ManyToMany and polymorphic pivot tables, in foreign-key dependency order. The only
 * place where the schema engine meets EntityMetadata — another front-end (a Prisma-style schema file) can
 * produce Tables its own way and reuse introspection, comparison, SQL and migration generation unchanged.
 *
 *   $tables = (new EntitySchemaBuilder)->tables([User::class, Post::class]);   // array<string, Table>
 */
final class EntitySchemaBuilder
{
    /** @var array<string, array<string, true>> "Class|id_column" => key types of the MorphOne/MorphMany parents */
    private array $morphParentTypes = [];

    /**
     * The tables the entities describe, in dependency order (join tables last).
     *
     * @param list<class-string> $entities
     * @return array<string, Table>
     */
    public function tables(array $entities): array
    {
        $tables = [];
        foreach ($this->blueprints($entities) as $blueprint) {
            $tables[$blueprint->table] = $blueprint->toTable();
        }

        return $tables;
    }

    /**
     * The #[ViewEntity] views among the entities (synchronize: true ones), keyed by name. $expression turns a
     * view's metadata into its SQL (DataSource::viewExpression() resolves callables / query builders); without
     * it, only string expressions are accepted. `materialized` is kept on PostgreSQL only.
     *
     * @param list<class-string>                $entities
     * @param (\Closure(EntityMetadata): string)|null $expression
     * @return array<string, \Nesto\Orm\Schema\View>
     */
    public function views(array $entities, ?\Closure $expression = null, string $driver = 'sqlite'): array
    {
        $views = [];
        foreach ($entities as $class) {
            $meta = MetadataRegistry::get($class);
            if (! $meta->isView() || ! $meta->synchronize) {
                continue;
            }
            $sql = $expression !== null ? $expression($meta) : $meta->view->expression;
            if (! is_string($sql) || trim($sql) === '') {
                throw new \Nesto\Orm\OrmException("View entity {$meta->class} needs an expression: SQL, or a [Class::class, 'method'] callable returning SQL or a QueryBuilder.");
            }
            $views[$meta->table] = new \Nesto\Orm\Schema\View($meta->table, trim($sql), $meta->view->materialized && $driver === 'pgsql', $meta->view->dependsOn);
        }

        return $views;
    }

    /** @param list<class-string> $entities @return list<Blueprint> in dependency order (join tables last) */
    public function blueprints(array $entities): array
    {
        $metas = [];
        foreach ($entities as $class) {
            $meta = MetadataRegistry::get($class);
            // single table inheritance: one table, built from the root (and every child entity)
            if ($meta->isView()) {
                continue; // views: see views()
            }
            $metas[$meta->table] = $meta->inheritanceRoot !== null ? MetadataRegistry::get($meta->inheritanceRoot) : $meta;
        }
        /** @var list<EntityMetadata> $mapped every entity mapped to the tables, child entities included */
        $mapped = [];
        foreach ($metas as $meta) {
            array_push($mapped, $meta, ...$meta->descendantMetadatas());
        }
        // MorphTo id columns without a declared type take the key type of the entities pointing at them
        $this->morphParentTypes = [];
        foreach ($mapped as $meta) {
            foreach ($meta->relations as $relation) {
                if ($relation->type === RelationType::MorphOne || $relation->type === RelationType::MorphMany) {
                    $key = $meta->column($relation->localKey ?? (string) $meta->primary->property) ?? $meta->primary;
                    $this->morphParentTypes[$relation->target . '|' . $relation->morphIdColumn][MetadataRegistry::keyType($key->type)] = true;
                }
            }
        }
        $ordered = [];
        $visiting = [];
        $visit = function (EntityMetadata $meta) use (&$visit, &$ordered, &$visiting, $metas): void {
            if (array_key_exists($meta->table, $ordered)) {
                return;
            }
            if (isset($visiting[$meta->table])) {
                return; // cycle (self reference / mutual FKs): order as encountered
            }
            $visiting[$meta->table] = true;
            foreach ([$meta, ...$meta->descendantMetadatas()] as $tableMeta) {
                foreach ($tableMeta->relations as $relation) {
                    if ($relation->joinColumn === null) {
                        continue;
                    }
                    $target = MetadataRegistry::get($relation->target);
                    if ($target->table !== $meta->table) {
                        $visit($metas[$target->table] ?? ($target->inheritanceRoot !== null ? MetadataRegistry::get($target->inheritanceRoot) : $target));
                    }
                }
            }
            $ordered[$meta->table] = $meta->synchronize ? $this->entityBlueprint($meta) : null;
        };
        foreach ($metas as $meta) {
            $visit($meta);
        }
        $blueprints = array_values(array_filter($ordered));
        $joinTables = [];
        foreach ($mapped as $meta) {
            if ($meta->tree?->closureTable !== null && $meta->synchronize) {
                $joinTables[$meta->tree->closureTable] ??= $this->closureBlueprint($meta);
            }
            foreach ($meta->relations as $relation) {
                if ($relation->type === RelationType::ManyToMany && $relation->owning && $relation->joinTableSynchronize) {
                    $targetMeta = MetadataRegistry::get($relation->target);
                    [$table, $ownerColumn, $targetColumn] = QueryBuilder::joinTableFor($relation, $meta, $targetMeta);
                    if (isset($joinTables[$table])) {
                        continue;
                    }
                    $bp = new Blueprint($table);
                    foreach ([[$ownerColumn, $meta, $relation->joinForeignKeyName], [$targetColumn, $targetMeta, $relation->inverseJoinForeignKeyName]] as [$column, $refMeta, $fkName]) {
                        $fk = $bp->add($this->fkColumn($column, $refMeta->primary));
                        $fk->referencedTable = $refMeta->table;
                        $fk->referencedColumn = $refMeta->primary->name;
                        $fk->onDelete($relation->onDelete ?? 'CASCADE')->onUpdate($relation->onUpdate)->constraintName($fkName);
                        if ($relation->createForeignKeyConstraints) {
                            $bp->foreignKeys[] = $fk;
                        }
                    }
                    $bp->primary([$ownerColumn, $targetColumn]);
                    $joinTables[$table] = $bp;
                }
            }
        }

        foreach ($this->morphPivotBlueprints($mapped) as $table => $bp) {
            $joinTables[$table] ??= $bp;
        }

        return array_merge($blueprints, array_values($joinTables));
    }

    /**
     * Pivot tables of MorphToMany / MorphedByMany ("taggables": tag_id, taggable_type, taggable_id).
     * The id column is bigint for integer keys, uuid for uuid keys, varchar when the owners' keys differ.
     *
     * @param list<EntityMetadata> $metas
     * @return array<string, Blueprint>
     */
    private function morphPivotBlueprints(array $metas): array
    {
        $pivots = [];
        foreach ($metas as $meta) {
            foreach ($meta->relations as $relation) {
                if ($relation->type !== RelationType::MorphToMany && $relation->type !== RelationType::MorphedByMany) {
                    continue;
                }
                $toMany = $relation->type === RelationType::MorphToMany;
                $related = $toMany ? MetadataRegistry::get($relation->target) : $meta;
                $pivot = $pivots[(string) $relation->joinTable] ?? [
                    'related' => $related,
                    'relatedColumn' => (string) ($toMany ? $relation->inverseJoinColumn : $relation->joinTableColumn),
                    'typeColumn' => (string) $relation->morphTypeColumn,
                    'idColumn' => (string) ($toMany ? $relation->joinTableColumn : $relation->inverseJoinColumn),
                    'idTypes' => [],
                ];
                $pivot['idTypes'][MetadataRegistry::keyType(MetadataRegistry::primaryType((string) $relation->morphClass))] = true;
                $pivots[(string) $relation->joinTable] = $pivot;
            }
        }
        $blueprints = [];
        foreach ($pivots as $table => $pivot) {
            $bp = new Blueprint($table);
            $fk = $bp->add($this->fkColumn($pivot['relatedColumn'], $pivot['related']->primary));
            $fk->referencedTable = $pivot['related']->table;
            $fk->referencedColumn = $pivot['related']->primary->name;
            $fk->onDeleteAction = 'CASCADE';
            $bp->foreignKeys[] = $fk;
            $bp->add(new ColumnDefinition($pivot['typeColumn'], 'string', 255));
            $idType = count($pivot['idTypes']) === 1 ? (string) array_key_first($pivot['idTypes']) : 'string';
            $bp->add(new ColumnDefinition($pivot['idColumn'], $idType, $idType === 'string' ? 255 : null));
            $bp->primary([$pivot['relatedColumn'], $pivot['typeColumn'], $pivot['idColumn']]);
            $bp->index([$pivot['typeColumn'], $pivot['idColumn']]);
            $blueprints[$table] = $bp;
        }

        return $blueprints;
    }

    /**
     * The table of an entity. A single table inheritance root also gets the columns, indexes and constraints of its
     * child entities (columns only a child has are nullable: the other types' rows leave them empty).
     */
    public function entityBlueprint(EntityMetadata $meta): Blueprint
    {
        $bp = new Blueprint($meta->table);
        foreach ($meta->columns as $column) {
            $this->addColumn($bp, $meta, $column);
        }
        $this->addConstraints($bp, $meta);
        $added = $meta->columns;
        foreach ($meta->descendantMetadatas() as $child) {
            foreach ($child->columns as $column) {
                if (! isset($added[$column->name])) {
                    $added[$column->name] = $column;
                    $this->addColumn($bp, $child, $column, childOnly: true);
                }
            }
            $this->addConstraints($bp, $child);
        }

        return $bp;
    }

    /** One entity column (with its foreign key) on the blueprint; $childOnly forces it nullable. */
    private function addColumn(Blueprint $bp, EntityMetadata $meta, ColumnMetadata $column, bool $childOnly = false): void
    {
        if ($column->primary) {
            $def = $bp->add(new ColumnDefinition($column->name, $column->type, $column->length, $column->precision, $column->scale))->primary();
            if ($column->generated && $column->generationStrategy !== 'uuid') {
                $def->autoIncrement();
            }
            $this->applyColumnOptions($def, $column, $meta);

            return;
        }
        if ($column->morph !== null && $column->property === null) {
            // MorphTo type / id column (no FK: the table it points to depends on the row)
            $type = $column->type;
            $length = $column->length;
            $relation = $meta->relation((string) $column->relation);
            if ($column->morph === 'id' && $relation->morphIdType === null) {
                $types = $this->morphParentTypes[$meta->class . '|' . $column->name] ?? [];
                $type = count($types) > 1 ? 'string' : ((string) array_key_first($types) ?: 'bigint');
                $length = $type === 'string' ? 255 : null;
            }
            $bp->add(new ColumnDefinition($column->name, $type, $length))->nullable($column->nullable || $childOnly);

            return;
        }
        if ($column->relation !== null && $column->morph === null) {
            $relation = $meta->relation($column->relation);
            $targetMeta = MetadataRegistry::get($relation->target);
            $fk = $this->fkColumn($column->name, $targetMeta->column($relation->referencedColumn) ?? $targetMeta->primary);
            $fk->nullable($column->nullable || $childOnly);
            $fk->referencedTable = $targetMeta->table;
            $fk->referencedColumn = $targetMeta->columnName($relation->referencedColumn);
            $fk->onDelete($relation->onDelete)->onUpdate($relation->onUpdate)->constraintName($relation->foreignKeyConstraintName);
            if ($relation->type === RelationType::OneToOne || $column->unique) {
                $fk->unique();
            }
            if ($column->property !== null) {
                // an explicit #[Column] for the foreign key: its options win
                $this->applyColumnOptions($fk, $column, $meta);
                if ($childOnly) {
                    $fk->nullable();
                }
            }
            $bp->add($fk);
            if ($relation->createForeignKeyConstraints) {
                $bp->foreignKeys[] = $fk;
            }

            return;
        }
        $def = $bp->add(new ColumnDefinition($column->name, $column->type, $column->length, $column->precision, $column->scale));
        $def->nullable($column->nullable || $childOnly);
        if ($column->hasDefault && $column->default !== null) {
            $def->default(ColumnFactory::databaseDefault($column));
        }
        if ($column->unique) {
            $def->unique();
        }
        if ($column->generationStrategy === 'increment' || $column->generationStrategy === 'identity') {
            $def->autoIncrement(); // PostgreSQL only (SERIAL): Grammar refuses it elsewhere
        }
        $this->applyColumnOptions($def, $column, $meta);
        if ($childOnly) {
            $def->nullable();
        }
    }

    /** Indexes, unique constraints and checks of an entity (those the table already has from a parent are skipped). */
    private function addConstraints(Blueprint $bp, EntityMetadata $meta): void
    {
        foreach ($meta->indexes as $index) {
            $columns = array_map(fn (string $c) => $meta->columnName($c), $index['columns']);
            if ($this->hasIndex($bp, $columns, $index['unique'], $index['name'])) {
                continue;
            }
            if ($index['unique']) {
                $bp->unique($columns, $index['name'], $index['where'] ?? null);
            } else {
                $bp->index($columns, $index['name'], $index['where'] ?? null);
            }
        }
        foreach ($meta->uniques as $unique) {
            $columns = array_map(fn (string $c) => $meta->columnName($c), $unique['columns']);
            if (! $this->hasIndex($bp, $columns, true, $unique['name'])) {
                $bp->unique($columns, $unique['name']);
            }
        }
        foreach ($meta->checks as $check) {
            foreach ($bp->checks as $existing) {
                if ($existing['expression'] === $check['expression']) {
                    continue 2;
                }
            }
            $bp->check($check['expression'], $check['name']);
        }
    }

    /** @param list<string> $columns */
    private function hasIndex(Blueprint $bp, array $columns, bool $unique, ?string $name): bool
    {
        foreach ($bp->indexes as $index) {
            if (($name !== null && $index['name'] === $name) || ($index['columns'] === $columns && $index['unique'] === $unique)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The closure junction table of a closure-table tree (TypeORM's "{table}_closure"): ancestor / descendant
     * columns referencing the entity's key (ON DELETE CASCADE), together the primary key.
     */
    private function closureBlueprint(EntityMetadata $meta): Blueprint
    {
        $tree = $meta->tree;
        $bp = new Blueprint((string) $tree->closureTable);
        foreach ([(string) $tree->ancestorColumn, (string) $tree->descendantColumn] as $column) {
            $fk = $bp->add($this->fkColumn($column, $meta->primary));
            $fk->referencedTable = $meta->table;
            $fk->referencedColumn = $meta->primary->name;
            $fk->onDelete('CASCADE');
            $bp->foreignKeys[] = $fk;
        }
        $bp->primary([(string) $tree->ancestorColumn, (string) $tree->descendantColumn]);

        return $bp;
    }

    /**
     * Comment, unsigned, charset / collation, enum values (PostgreSQL enum type "{table}_{column}_enum" unless
     * enumName says otherwise, like TypeORM), generated values / columns and array columns of a mapped column.
     */
    private function applyColumnOptions(ColumnDefinition $def, ColumnMetadata $column, EntityMetadata $meta): void
    {
        $enumName = $column->enumName ?? (in_array($column->type, ['enum', 'simple-enum'], true) ? $meta->table . '_' . $column->name . '_enum' : null);
        $def->comment($column->comment)->unsigned($column->unsigned)->charset($column->charset)->collation($column->collation)->enumName($enumName);
        $def->array($column->array);
        $def->generated = $column->generationStrategy;
        if ($column->generatedIdentity !== null) {
            $def->generatedAs($column->generatedIdentity);
        }
        if ($column->isComputed()) {
            $column->generatedType === 'VIRTUAL' ? $def->virtualAs((string) $column->asExpression) : $def->storedAs((string) $column->asExpression);
            $def->nullable();
        }
        if ($column->enumValues !== []) {
            $def->values = $column->enumValues;
        } elseif ($column->enumClass !== null) {
            $def->values = array_map(fn (\BackedEnum $c) => $c->value, $column->enumClass::cases());
        }
    }

    private function fkColumn(string $name, ColumnMetadata $referenced): ForeignKeyDefinition
    {
        $type = match (true) {
            $referenced->generated => $referenced->type, // the generated key's own type (bigint unless set)
            $referenced->type === 'uuid' => 'uuid',
            in_array($referenced->type, ['int', 'bigint', 'integer'], true) => 'bigint',
            default => $referenced->type,
        };
        $fk = new ForeignKeyDefinition($name, $type, $referenced->length);
        $fk->referencedColumn = $referenced->name;

        return $fk;
    }
}
