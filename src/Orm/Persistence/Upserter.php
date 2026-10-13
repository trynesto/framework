<?php

declare(strict_types=1);

namespace Nesto\Orm\Persistence;

use DateTimeImmutable;
use Nesto\Orm\Connection;
use Nesto\Orm\DataSource;
use Nesto\Orm\EntityMetadata;
use Nesto\Orm\InsertResult;
use Nesto\Orm\Mapping\ColumnMetadata;
use Nesto\Orm\Mapping\EmbeddedValues;
use Nesto\Orm\Mapping\TypeConverter;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\OrmException;
use Nesto\Orm\Repository;

/**
 * Repository::upsert() (TypeORM's upsert()): one INSERT … ON CONFLICT DO UPDATE per group of rows with the same
 * columns, then one SELECT reading back the keys and generated values of the written rows.
 *
 *   PostgreSQL / SQLite   ON CONFLICT (conflict columns) DO UPDATE SET c = EXCLUDED.c …
 *                         [WHERE t.c IS DISTINCT FROM EXCLUDED.c OR …]       (skipUpdateIfNoValuesChanged)
 *   MySQL 8.0.19+         … AS nesto_new ON DUPLICATE KEY UPDATE c = nesto_new.c …   (any unique key conflicts)
 *   MariaDB / older MySQL … ON DUPLICATE KEY UPDATE c = VALUES(c) …
 *
 * Every given column is overwritten on conflict except the primary key and the #[CreateDateColumn]; the
 * #[UpdateDateColumn] is stamped and the #[VersionColumn] bumped (only when a value changed, with
 * skipUpdateIfNoValuesChanged — on MySQL / MariaDB too, where TypeORM ignores that option).
 * Entities get beforeInsert / afterInsert listeners and subscribers (as TypeORM's insert query builder fires them).
 *
 * Options as TypeORM's: `upsertType` (on-conflict-do-update | on-duplicate-key-update | primary-key; one the platform
 * does not support is an error) and `indexPredicate` (PostgreSQL: ON CONFLICT (…) WHERE predicate, to target a
 * partial unique index; an error elsewhere, like in TypeORM).
 */
final class Upserter
{
    private readonly Connection $conn;

    public function __construct(private readonly DataSource $dataSource, private readonly EntityMetadata $meta)
    {
        $this->conn = $dataSource->getConnection();
    }

    /** @param list<string>|array<string, mixed> $conflictPathsOrOptions */
    public function upsert(object|array $entities, array $conflictPathsOrOptions): InsertResult
    {
        $this->meta->assertWritable('upsert()');
        $items = is_object($entities) || ($entities !== [] && ! array_is_list($entities)) ? [$entities] : array_values($entities);
        if ($items === []) {
            return new InsertResult([], [], 0);
        }
        [$conflict, $skipUnchanged, $indexPredicate] = $this->options($conflictPathsOrOptions);

        return $this->dataSource->runInTransaction(function () use ($items, $conflict, $skipUnchanged, $indexPredicate): InsertResult {
            $events = $this->dataSource->broadcaster();
            $rows = [];
            foreach ($items as $i => $item) {
                $events->insert('beforeInsert', $this->meta, $item);
                $rows[$i] = $this->row($item);
                foreach ($conflict as $column) {
                    if (! array_key_exists($column, $rows[$i])) {
                        throw new OrmException("upsert(): every row needs a value for the conflict column \"$column\".");
                    }
                }
            }
            $groups = [];
            foreach ($rows as $i => $row) {
                $groups[implode(',', array_keys($row))][$i] = $row;
            }
            $affected = 0;
            foreach ($groups as $group) {
                [$sql, $params] = $this->statement(array_values($group), $conflict, $skipUnchanged, $indexPredicate);
                $affected += $this->conn->execute($sql, $params);
            }
            [$identifiers, $generated] = $this->readBack($items, $rows, $conflict);
            foreach ($items as $item) {
                $events->insert('afterInsert', $this->meta, $item);
            }

            return new InsertResult($identifiers, $generated, $affected);
        });
    }

    /**
     * TypeORM's upsert types and the ones each platform supports (the first is the default): PostgreSQL / SQLite
     * ON CONFLICT … DO UPDATE, MySQL / MariaDB ON DUPLICATE KEY UPDATE. "primary-key" (CockroachDB's UPSERT INTO)
     * exists on none of them.
     */
    public const UPSERT_TYPES = ['on-conflict-do-update', 'on-duplicate-key-update', 'primary-key'];

    /** @return list<string> */
    public static function supportedUpsertTypes(Connection $conn): array
    {
        return $conn->driver() === 'mysql' ? ['on-duplicate-key-update'] : ['on-conflict-do-update'];
    }

    /** @return array{0: list<string>, 1: bool, 2: ?string} conflict column names, skipUpdateIfNoValuesChanged, indexPredicate */
    private function options(array $option): array
    {
        $predicate = null;
        if (array_is_list($option)) {
            $paths = $option;
            $skip = false;
        } else {
            $unknown = array_diff(array_keys($option), ['conflictPaths', 'skipUpdateIfNoValuesChanged', 'upsertType', 'indexPredicate']);
            if ($unknown !== []) {
                throw new OrmException('Unknown upsert option "' . implode('", "', $unknown) . '" (expected conflictPaths, skipUpdateIfNoValuesChanged, upsertType, indexPredicate).');
            }
            $paths = $option['conflictPaths'] ?? [];
            if (! array_is_list($paths)) {
                $paths = array_keys(array_filter($paths));
            }
            $skip = (bool) ($option['skipUpdateIfNoValuesChanged'] ?? false);
            $type = $option['upsertType'] ?? null;
            if ($type !== null) {
                $supported = self::supportedUpsertTypes($this->conn);
                if (! in_array($type, self::UPSERT_TYPES, true)) {
                    throw new OrmException('Unknown upsertType ' . json_encode($type) . ' (expected ' . implode(', ', self::UPSERT_TYPES) . ').');
                }
                if (! in_array($type, $supported, true)) {
                    throw new OrmException("Upsert type \"$type\" is not supported by the {$this->conn->platform()} driver (supported: " . implode(', ', $supported) . ').');
                }
            }
            if (($option['indexPredicate'] ?? null) !== null) {
                if ($this->conn->driver() !== 'pgsql') {
                    // like TypeORM: only PostgreSQL infers partial unique indexes from ON CONFLICT (…) WHERE …
                    throw new OrmException("indexPredicate option is not supported by the current database driver ({$this->conn->platform()}).");
                }
                $predicate = (string) $option['indexPredicate'];
            }
        }
        if ($paths === []) {
            throw new OrmException('upsert() needs the conflict paths: the (unique) columns identifying an existing row, e.g. [\'email\'].');
        }
        $columns = [];
        foreach ($paths as $path) {
            $column = $this->meta->column((string) $path) ?? ($this->meta->relation((string) $path)?->joinColumn !== null ? $this->meta->columns[$this->meta->relation((string) $path)->joinColumn] : null);
            if ($column === null) {
                throw new OrmException("upsert(): {$this->meta->class} has no column \"$path\".");
            }
            $columns[] = $column->name;
        }

        return [$columns, $skip, $predicate];
    }

    /** Column => database value of one entity / plain row, with the defaults save() would give a new row. */
    private function row(object|array $item): array
    {
        $meta = $this->meta;
        $now = new DateTimeImmutable;
        if (is_object($item)) {
            if (! $item instanceof $meta->class) {
                throw new OrmException('upsert() on ' . $meta->class . ' got a ' . $item::class . '.');
            }
            if ($meta->primary->generated && $meta->primary->type === 'uuid' && $meta->primaryValue($item) === null) {
                $meta->setPrimaryValue($item, Repository::uuid());
            }
            foreach ([$meta->createdAt, $meta->updatedAt] as $prop) {
                if ($prop !== null && (! $meta->isInitialized($item, $prop) || $item->{$prop} === null)) {
                    $item->{$prop} = $now;
                }
            }
            if ($meta->version !== null && (! $meta->isInitialized($item, $meta->version) || $item->{$meta->version} === null)) {
                $item->{$meta->version} = 1;
            }
            $row = $this->dataSource->hydrator()->toRow($meta, $item);
            if ($meta->primary->generated && ($row[$meta->primary->name] ?? null) === null) {
                unset($row[$meta->primary->name]);
            }

            return GeneratedValues::insertRow($meta, $row);
        }
        $row = [];
        foreach (EmbeddedValues::flatten($meta, $item) as $key => $value) {
            $column = $meta->column((string) $key);
            $relation = $column === null ? $meta->relation((string) $key) : null;
            if ($column === null && $relation?->joinColumn !== null) {
                $column = $meta->columns[$relation->joinColumn];
                if (is_object($value) && ! $value instanceof \BackedEnum && ! $value instanceof \DateTimeInterface) {
                    $value = MetadataRegistry::get($relation->target)->primaryValue($value);
                }
            }
            if ($column === null) {
                throw new OrmException("upsert(): {$meta->class} has no column \"$key\".");
            }
            $row[$column->name] = TypeConverter::toDatabase($column, $value);
        }
        foreach ([$meta->createdAt, $meta->updatedAt] as $prop) {
            if ($prop !== null && ! array_key_exists($meta->columnName($prop), $row)) {
                $row[$meta->columnName($prop)] = TypeConverter::toDatabase($meta->column($prop), $now);
            }
        }
        if (($version = $meta->versionColumn()) !== null && ! array_key_exists($version->name, $row)) {
            $row[$version->name] = 1;
        }
        if ($meta->primary->generated && $meta->primary->type === 'uuid' && empty($row[$meta->primary->name])) {
            $row[$meta->primary->name] = Repository::uuid();
        }

        return GeneratedValues::insertRow($meta, $row);
    }

    /**
     * @param list<array<string, mixed>> $rows same columns
     * @param list<string>               $conflict
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function statement(array $rows, array $conflict, bool $skipUnchanged, ?string $indexPredicate = null): array
    {
        $q = $this->conn->quote(...);
        $meta = $this->meta;
        $table = $q($meta->table);
        $columns = array_keys($rows[0]);
        $params = [];
        $values = [];
        foreach ($rows as $r => $row) {
            $set = [];
            foreach (array_values($row) as $c => $value) {
                $params["u{$r}_$c"] = $value;
                $set[] = ":u{$r}_$c";
            }
            $values[] = '(' . implode(', ', $set) . ')';
        }
        $sql = "INSERT INTO $table (" . implode(', ', array_map($q, $columns)) . ') VALUES ' . implode(', ', $values);

        $createdAt = $meta->createdAt !== null ? $meta->columnName($meta->createdAt) : null;
        $updatedAt = $meta->updatedAt !== null ? $meta->columnName($meta->updatedAt) : null;
        $version = $meta->versionColumn()?->name;
        // columns compared by skipUpdateIfNoValuesChanged / overwritten from the new row
        $compared = array_values(array_filter($columns, fn (string $c) => ! in_array($c, [$meta->primary->name, $createdAt, $updatedAt, $version], true)));
        $overwrite = array_values(array_filter($columns, fn (string $c) => ! in_array($c, [$meta->primary->name, $createdAt, $version], true)));

        if ($this->conn->driver() === 'mysql') {
            $alias = $this->conn->platform() === 'mysql' && version_compare($this->numericVersion(), '8.0.19', '>=') ? 'nesto_new' : null;
            $new = fn (string $c): string => $alias !== null ? $q($alias) . '.' . $q($c) : 'VALUES(' . $q($c) . ')';
            if ($alias !== null) {
                $sql .= ' AS ' . $q($alias);
            }
            $old = fn (string $c): string => $table . '.' . $q($c); // qualified: the row alias has the same columns
            $sets = [];
            // MySQL assigns left to right, later expressions seeing earlier assignments: the update date and the
            // version are computed first, while the compared columns still hold the stored values
            $unchanged = $compared === [] ? '1 = 1' : implode(' AND ', array_map(fn (string $c) => $old($c) . ' <=> ' . $new($c), $compared));
            if ($updatedAt !== null && in_array($updatedAt, $columns, true)) {
                $sets[] = $old($updatedAt) . ' = ' . ($skipUnchanged ? "IF($unchanged, " . $old($updatedAt) . ', ' . $new($updatedAt) . ')' : $new($updatedAt));
            }
            if ($version !== null) {
                $sets[] = $old($version) . ' = ' . ($skipUnchanged ? "IF($unchanged, " . $old($version) . ', ' . $old($version) . ' + 1)' : $old($version) . ' + 1');
            }
            foreach ($overwrite as $c) {
                if ($c !== $updatedAt) {
                    $sets[] = $old($c) . ' = ' . $new($c);
                }
            }
            if ($sets === []) {
                $sets[] = $old($conflict[0]) . ' = ' . $old($conflict[0]); // nothing to overwrite: keep the row
            }

            return [$sql . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets), $params];
        }

        $sql .= ' ON CONFLICT (' . implode(', ', array_map($q, $conflict)) . ')' . ($indexPredicate !== null ? " WHERE ($indexPredicate)" : '');
        $sets = array_map(fn (string $c) => $q($c) . ' = EXCLUDED.' . $q($c), $overwrite);
        if ($version !== null) {
            $sets[] = $q($version) . ' = ' . $table . '.' . $q($version) . ' + 1';
        }
        if ($sets === []) {
            return [$sql . ' DO NOTHING', $params];
        }
        $sql .= ' DO UPDATE SET ' . implode(', ', $sets);
        if ($skipUnchanged && $compared !== []) {
            $distinct = $this->conn->driver() === 'pgsql' ? 'IS DISTINCT FROM' : 'IS NOT';
            $sql .= ' WHERE ' . implode(' OR ', array_map(fn (string $c) => $table . '.' . $q($c) . " $distinct EXCLUDED." . $q($c), $compared));
        }

        return [$sql, $params];
    }

    /**
     * One SELECT for the written rows (matched by their conflict columns): their keys become the identifiers
     * and, for entities, the primary key / dates / version are written back and the snapshot recorded.
     *
     * @param list<object|array>               $items
     * @param array<int, array<string, mixed>> $rows
     * @param list<string>                     $conflict
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function readBack(array $items, array $rows, array $conflict): array
    {
        $meta = $this->meta;
        $q = $this->conn->quote(...);
        $generatedColumns = array_values(array_filter([
            $meta->primary,
            $meta->createdAt !== null ? $meta->column($meta->createdAt) : null,
            $meta->updatedAt !== null ? $meta->column($meta->updatedAt) : null,
            $meta->versionColumn(),
        ]));
        $select = array_unique([...array_map(fn (ColumnMetadata $c) => $c->name, $generatedColumns), ...$conflict]);
        $where = [];
        $params = [];
        foreach ($rows as $i => $row) {
            $parts = [];
            foreach ($conflict as $c => $column) {
                $parts[] = $q($column) . ' = :k' . $i . '_' . $c;
                $params['k' . $i . '_' . $c] = $row[$column];
            }
            $where[] = '(' . implode(' AND ', $parts) . ')';
        }
        $found = $this->conn->fetchAll(
            'SELECT ' . implode(', ', array_map($q, $select)) . ' FROM ' . $q($meta->table) . ' WHERE ' . implode(' OR ', $where),
            $params,
        );
        $identifiers = [];
        $generated = [];
        $state = $this->dataSource->state();
        foreach ($rows as $i => $row) {
            $match = null;
            foreach ($found as $candidate) {
                $same = true;
                foreach ($conflict as $column) {
                    if (! Persister::same($candidate[$column], $row[$column], $meta->columns[$column]->type)) {
                        $same = false;
                        break;
                    }
                }
                if ($same) {
                    $match = $candidate;
                    break;
                }
            }
            $pkProperty = (string) $meta->primary->property;
            $identifiers[] = [$pkProperty => $match === null ? null : TypeConverter::toPhp($meta->primary, $match[$meta->primary->name])];
            $map = [];
            foreach ($generatedColumns as $column) {
                if ($match !== null && $column->property !== null) {
                    $map[$column->property] = TypeConverter::toPhp($column, $match[$column->name]);
                }
            }
            $generated[] = $map;
            $item = $items[$i];
            if (is_object($item) && $match !== null) {
                foreach ($map as $property => $value) {
                    $item->{$property} = $value;
                }
                $state->setSnapshot($item, array_merge($row, array_intersect_key($match, array_flip(array_map(fn (ColumnMetadata $c) => $c->name, $generatedColumns)))));
            }
        }

        return [$identifiers, $generated];
    }

    private function numericVersion(): string
    {
        preg_match('/\d+(\.\d+)*/', (string) $this->conn->serverVersion(), $m);

        return $m[0] ?? '0';
    }
}
