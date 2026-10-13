<?php

declare(strict_types=1);

namespace Nesto\Orm\Migration;

use Nesto\Orm\EntityMetadata;
use Nesto\Orm\Mapping\EntitySchemaBuilder;
use Nesto\Orm\Schema\Comparison\Comparator;
use Nesto\Orm\Schema\Comparison\SchemaDiff;
use Nesto\Orm\Schema\Introspection\Introspector;
use Nesto\Orm\Schema\Table;

/**
 * Brings the database in line with entity metadata — DataSource::synchronize(), `schema:sync`,
 * `schema:log` and `migration:generate`. Glue between the layers: the entities' tables come from
 * Mapping\EntitySchemaBuilder, the live ones from Schema\Introspection, Schema\Comparison compares them and
 * DiffPlan applies the result through Schema / Blueprint. The diff creates missing tables (and ManyToMany /
 * polymorphic pivot tables), adds, changes and drops columns, and replaces changed keys, indexes, foreign
 * keys and checks — like TypeORM's synchronize — and creates, drops and recreates #[ViewEntity] views whose
 * expression changed. Tables and views that belong to no entity are never dropped.
 * Running it again is a no-op.
 *
 *   $sync->diff([User::class])->isEmpty();
 *   $sync->log([User::class]);          // SQL it would run
 *   $sync->synchronize([User::class]);
 */
final class SchemaSynchronizer
{
    /**
     * @param array<string, Table> $extraTables tables that belong to the schema without being entities (the query
     *        result cache table): created and compared like entity tables
     * @param (\Closure(EntityMetadata): string)|null $viewExpression SQL of a view entity (DataSource::viewExpression());
     *        without it only string expressions work
     */
    public function __construct(
        private readonly Schema $schema,
        private readonly array $extraTables = [],
        private readonly ?\Closure $viewExpression = null,
    ) {}

    /** @param list<class-string> $entities */
    public function synchronize(array $entities): void
    {
        (new DiffPlan($this->diff($entities)))->apply($this->schema);
    }

    /** @param list<class-string> $entities @return list<string> the statements synchronize() would run */
    public function log(array $entities): array
    {
        return (new DiffPlan($this->diff($entities)))->toSql($this->schema);
    }

    /** What synchronize() would change. @param list<class-string> $entities */
    public function diff(array $entities): SchemaDiff
    {
        $wanted = $this->tables($entities);
        $introspector = Introspector::for($this->schema->getConnection());
        $current = $introspector->tables(array_keys($wanted));
        $comparator = new Comparator($this->schema->grammar());
        $diff = $comparator->compare($current, $wanted);
        $views = $this->views($entities);
        if ($views !== []) {
            $comparator->compareViews($diff, $introspector->views($this->schema->metadataTable(), array_keys($views)), $views, [], self::touchedTables($diff));
        }

        return $diff;
    }

    /**
     * Tables whose changes force the views selecting from them to be recreated: PostgreSQL refuses to alter
     * or drop columns a view uses, SQLite to rebuild a table (its way to alter one) a view selects from;
     * MySQL / MariaDB re-resolve views when they are queried.
     *
     * @return list<string>
     */
    public static function touchedTables(SchemaDiff $diff): array
    {
        $altered = match ($diff->platform) {
            'pgsql' => array_filter($diff->altered, fn ($d) => $d->changedColumns !== [] || $d->droppedColumns !== []),
            'sqlite' => $diff->altered,
            default => [],
        };
        if ($altered === [] && ($diff->platform === 'mysql' || $diff->platform === 'mariadb')) {
            return [];
        }

        return array_values(array_unique([
            ...array_map(fn ($d) => $d->table, $altered),
            ...($diff->platform === 'mysql' || $diff->platform === 'mariadb' ? [] : array_map(fn (Table $t) => $t->name, $diff->dropped)),
        ]));
    }

    /**
     * The views the entities describe (#[ViewEntity]), in no particular order (Comparator orders them by dependsOn).
     *
     * @param list<class-string> $entities
     * @return array<string, \Nesto\Orm\Schema\View>
     */
    public function views(array $entities): array
    {
        return (new EntitySchemaBuilder)->views($entities, $this->viewExpression, $this->schema->getConnection()->driver());
    }

    /**
     * The tables the entities describe, in dependency order (join tables last).
     *
     * @param list<class-string> $entities
     * @return array<string, Table>
     */
    public function tables(array $entities): array
    {
        return (new EntitySchemaBuilder)->tables($entities) + $this->extraTables;
    }

    /** @param list<class-string> $entities */
    public function drop(array $entities): void
    {
        foreach ($entities as $class) {
            $meta = \Nesto\Orm\MetadataRegistry::get($class);
            if ($meta->isView()) {
                $this->schema->dropView($meta->table, $meta->view->materialized);
            }
        }
        $blueprints = array_reverse($this->blueprints($entities));
        $conn = $this->schema->getConnection();
        if ($conn->driver() === 'mysql') {
            $conn->execute('SET FOREIGN_KEY_CHECKS = 0');
        }
        if ($conn->driver() === 'sqlite') {
            $conn->execute('PRAGMA foreign_keys = OFF');
        }
        try {
            $enumTypes = [];
            foreach ($blueprints as $blueprint) {
                $this->schema->dropTableIfExists($blueprint->table);
                foreach ($blueprint->columns as $column) {
                    if ($column->enumName !== null) {
                        $enumTypes[$column->enumName] = true;
                    }
                }
            }
            if ($conn->driver() === 'pgsql') {
                // PostgreSQL native enum types of the dropped columns (like TypeORM's schema:drop)
                foreach (array_keys($enumTypes) as $type) {
                    $this->schema->statement('DROP TYPE IF EXISTS ' . $conn->quote($type) . ' CASCADE');
                }
            }
        } finally {
            if ($conn->driver() === 'mysql') {
                $conn->execute('SET FOREIGN_KEY_CHECKS = 1');
            }
            if ($conn->driver() === 'sqlite') {
                $conn->execute('PRAGMA foreign_keys = ON');
            }
        }
    }

    /** @param list<class-string> $entities @return list<Blueprint> in dependency order (join tables last) */
    public function blueprints(array $entities): array
    {
        return (new EntitySchemaBuilder)->blueprints($entities);
    }

    public function entityBlueprint(EntityMetadata $meta): Blueprint
    {
        return (new EntitySchemaBuilder)->entityBlueprint($meta);
    }
}
