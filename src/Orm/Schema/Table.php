<?php

declare(strict_types=1);

namespace Nesto\Orm\Schema;

/**
 * One table of the neutral schema model: columns, primary key, indexes / unique constraints, foreign keys
 * and checks. Produced by an Introspector (what a database has), by Blueprint::toTable() (what migrations or
 * entities want) or by any other front-end; compared by Comparison\Comparator. Column-level `unique` /
 * `index` flags are kept in the table-level lists instead.
 */
final class Table
{
    /** Table default character set / collation (MySQL/MariaDB introspection): columns using them report none. */
    public ?string $defaultCharset = null;
    public ?string $defaultCollation = null;
    /**
     * Name of the primary key constraint. Introspection reports it on PostgreSQL; a wanted table that sets it
     * gets a named constraint there (`CONSTRAINT "User_pkey" PRIMARY KEY (...)`) and is compared by it.
     * Null: the database's default name, not compared.
     */
    public ?string $primaryKeyName = null;

    /**
     * @param array<string, Column> $columns keyed by name, in table order
     * @param list<string>            $primaryKey
     * @param list<Index>       $indexes
     * @param list<ForeignKey>  $foreignKeys
     * @param list<Check>       $checks
     */
    public function __construct(
        public readonly string $name,
        public array $columns = [],
        public array $primaryKey = [],
        public array $indexes = [],
        public array $foreignKeys = [],
        public array $checks = [],
    ) {}

    public function column(string $name): ?Column
    {
        if (isset($this->columns[$name])) {
            return $this->columns[$name];
        }
        foreach ($this->columns as $key => $column) {
            if (strcasecmp($key, $name) === 0) {
                return $column;
            }
        }

        return null;
    }

    /** Deep copy (columns are mutable definitions). */
    public function copy(): self
    {
        $copy = clone $this;
        $copy->columns = array_map(fn (Column $c) => clone $c, $this->columns);

        return $copy;
    }

    /** FK constraint of a single referencing column list, if any. @param list<string> $columns */
    public function foreignKeyOn(array $columns): ?ForeignKey
    {
        foreach ($this->foreignKeys as $fk) {
            if (Normalizer::sameColumns($fk->columns, $columns)) {
                return $fk;
            }
        }

        return null;
    }
}
