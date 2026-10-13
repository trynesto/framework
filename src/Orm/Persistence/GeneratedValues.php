<?php

declare(strict_types=1);

namespace Nesto\Orm\Persistence;

use Nesto\Orm\Connection;
use Nesto\Orm\EntityMetadata;
use Nesto\Orm\Mapping\ColumnMetadata;
use Nesto\Orm\Mapping\PropertyAccess;
use Nesto\Orm\Mapping\TypeConverter;
use Nesto\Orm\Repository;

/**
 * Values the database (or the ORM) generates for a row, and the column options that keep values out of
 * INSERT / UPDATE (TypeORM insert: false, update: false / readonly, generated columns):
 *
 *   - uuid strategy (#[PrimaryGeneratedColumn('uuid')], #[Generated('uuid')]): PostgreSQL fills it with its
 *     column DEFAULT gen_random_uuid(); elsewhere the ORM generates a v4 UUID before the INSERT
 *   - increment (primary key: every database; #[Generated('increment')]: PostgreSQL) and generated columns
 *     (asExpression) are read back after the write: RETURNING on PostgreSQL, a SELECT by primary key elsewhere
 */
final class GeneratedValues
{
    /** Fill ORM-generated values (uuid off PostgreSQL) on a new entity, before its row is built. */
    public static function prepareInsert(EntityMetadata $meta, object $entity, string $driver): void
    {
        foreach ($meta->columns as $column) {
            if ($column->generationStrategy !== 'uuid' || $column->property === null || $driver === 'pgsql') {
                continue;
            }
            if (! PropertyAccess::isInitialized($entity, $column) || PropertyAccess::get($entity, $column) === null) {
                PropertyAccess::set($entity, $column, TypeConverter::toPhp($column, Repository::uuid()));
            }
        }
    }

    /**
     * The row of an INSERT: without columns that must not be written (insert: false, generated columns) and
     * without empty values the database generates; with the discriminator value of a single table inheritance
     * entity.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function insertRow(EntityMetadata $meta, array $row): array
    {
        foreach ($meta->columns as $column) {
            if (! array_key_exists($column->name, $row)) {
                continue;
            }
            if (! $column->insert || ($column->generationStrategy !== null && $row[$column->name] === null)) {
                unset($row[$column->name]);
            }
        }
        if ($meta->discriminatorColumn !== null && ! isset($row[$meta->discriminatorColumn])) {
            $row[$meta->discriminatorColumn] = $meta->discriminatorValue; // single table inheritance: the row's type
        }

        return $row;
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> without update: false / generated columns */
    public static function updateRow(EntityMetadata $meta, array $changes): array
    {
        foreach ($meta->columns as $column) {
            if (array_key_exists($column->name, $changes) && ! $column->update) {
                unset($changes[$column->name]);
            }
        }

        return $changes;
    }

    /**
     * Columns whose value the database decides on INSERT and that the entity should get back: generated
     * columns and generated values that were not written.
     *
     * @param array<string, mixed> $row the row actually inserted
     * @return list<ColumnMetadata>
     */
    public static function afterInsert(EntityMetadata $meta, array $row): array
    {
        $columns = [];
        foreach ($meta->columns as $column) {
            if ($column->property === null || $column->primary) {
                continue;
            }
            if ($column->isComputed() || ($column->generationStrategy !== null && ! array_key_exists($column->name, $row))) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /** Generated columns (recomputed by an UPDATE). @return list<ColumnMetadata> */
    public static function computed(EntityMetadata $meta): array
    {
        return array_values(array_filter($meta->columns, fn (ColumnMetadata $c) => $c->isComputed() && $c->property !== null));
    }

    /**
     * Read columns of a row by primary key and assign them to the entity. Returns column => database value.
     *
     * @param list<ColumnMetadata> $columns
     * @return array<string, mixed>
     */
    public static function reload(Connection $conn, EntityMetadata $meta, object $entity, array $columns, mixed $pk): array
    {
        $row = self::fetch($conn, $meta, $columns, $pk === null ? null : TypeConverter::toDatabase($meta->primary, $pk));
        self::assign($meta, $entity, $columns, $row);

        return $row;
    }

    /**
     * Column => database value of the row with primary key $key (a database value).
     *
     * @param list<ColumnMetadata> $columns
     * @return array<string, mixed>
     */
    public static function fetch(Connection $conn, EntityMetadata $meta, array $columns, mixed $key): array
    {
        if ($columns === [] || $key === null) {
            return [];
        }

        return $conn->fetchOne(
            'SELECT ' . implode(', ', array_map(fn (ColumnMetadata $c) => $conn->quote($c->name), $columns))
            . ' FROM ' . $conn->quote($meta->table) . ' WHERE ' . $conn->quote($meta->primary->name) . ' = :pk',
            ['pk' => $key],
        ) ?? [];
    }

    /** @param list<ColumnMetadata> $columns @param array<string, mixed> $row */
    public static function assign(EntityMetadata $meta, object $entity, array $columns, array $row): void
    {
        foreach ($columns as $column) {
            if (! array_key_exists($column->name, $row)) {
                continue;
            }
            $value = TypeConverter::toPhp($column, $row[$column->name]);
            if ($value !== null || PropertyAccess::allowsNull($entity, $column)) {
                PropertyAccess::set($entity, $column, $value);
            }
        }
    }
}
