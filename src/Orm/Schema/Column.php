<?php

declare(strict_types=1);

namespace Nesto\Orm\Schema;

/**
 * One column of the neutral schema model: the abstract type name (the #[Column] / Blueprint vocabulary:
 * "string", "int", "decimal", "json", ...) and its options. Introspection fills `rawType` with the type
 * exactly as the database reports it; comparisons use it, SQLite rebuilds keep it verbatim.
 * Migration\ColumnDefinition extends it with the fluent Blueprint API.
 */
class Column
{
    public bool $nullable = false;
    /** A value, a Nesto\Orm\SqlExpression (raw SQL) or null. */
    public mixed $default = null;
    public bool $hasDefault = false;
    /** Column-level UNIQUE (lifted into Table::$indexes by the model). */
    public bool $unique = false;
    /** Column-level index (lifted into Table::$indexes by the model). */
    public bool $index = false;
    public bool $primary = false;
    public bool $autoIncrement = false;
    public bool $unsigned = false;
    /** @var list<string|int> enum values */
    public array $values = [];
    public ?string $comment = null;
    public ?string $charset = null;
    public ?string $collation = null;
    /** PostgreSQL native enum type name (enum columns). */
    public ?string $enumName = null;
    /** Alter the existing column to this definition instead of adding it (Schema::table()). */
    public bool $change = false;
    /** Type exactly as the database reports it (set by introspection). */
    public ?string $rawType = null;
    /** Generated column: STORED or VIRTUAL, computed from $asExpression. */
    public ?string $generatedType = null;
    public ?string $asExpression = null;
    /** PostgreSQL identity column: ALWAYS or BY DEFAULT (auto-increment columns only). */
    public ?string $identity = null;
    /** PostgreSQL array of $type ("INTEGER[]"). */
    public bool $array = false;
    /** Value generated on insert: "uuid" gets DEFAULT gen_random_uuid() on PostgreSQL (the ORM fills it elsewhere). */
    public ?string $generated = null;
    /**
     * Exact SQL type declaration chosen by a front-end that knows native types (Prisma's `@db.VarChar(255)`,
     * its default `TIMESTAMP(3)`, ...): Grammar writes it verbatim instead of mapping `$type`. `$type` still
     * names the abstract type (implicit defaults, value conversion).
     */
    public ?string $sqlType = null;

    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
    ) {}

    /** A copy of this column as an instance of $class (Column or a subclass), optionally renamed. */
    public function copyAs(string $class, ?string $name = null): static|Column
    {
        $copy = new $class($name ?? $this->name, $this->type, $this->length, $this->precision, $this->scale);
        foreach (get_object_vars($this) as $property => $value) {
            if (property_exists($copy, $property) && ! in_array($property, ['name', 'type', 'length', 'precision', 'scale'], true)) {
                $copy->{$property} = $value;
            }
        }

        return $copy;
    }
}
