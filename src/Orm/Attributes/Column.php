<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * A regular column (TypeORM's @Column). The type is inferred from the PHP property type when omitted:
 * int, float, bool, string, ?DateTimeImmutable → datetime, array → json, BackedEnum → its backing type.
 *
 *   #[Column] public string $name;
 *   #[Column(type: 'text', nullable: true)] public ?string $bio = null;
 *   #[Column(name: 'is_active', default: true)] public bool $active = true;
 *   #[Column(type: 'decimal', precision: 10, scale: 2, unsigned: true)] public string $price;
 *   #[Column(type: 'varchar', length: 80, comment: 'Shown on the profile', collation: 'utf8mb4_bin')] public string $handle;
 *   #[Column(type: 'enum', enum: ['draft', 'published'])] public string $status = 'draft';
 *   #[Column(type: 'timestamp', default: new SqlExpression('CURRENT_TIMESTAMP'))] public ?DateTimeImmutable $seenAt = null;
 *
 * Types (TypeORM names): int/integer, smallint, bigint, tinyint, float, double, real, decimal/numeric,
 * boolean/bool, varchar/string, char, text (mediumtext, longtext), date, time, datetime, timestamp,
 * timestamptz, json, jsonb, uuid, enum, simple-array, simple-json, blob, binary/bytea.
 * `default` takes a value, a Nesto\Orm\SqlExpression (raw SQL such as CURRENT_TIMESTAMP) or, on PHP 8.5,
 * a static closure returning the SQL (TypeORM's `default: () => 'CURRENT_TIMESTAMP'`).
 * `precision` on datetime / timestamp / time columns is the fractional seconds precision.
 * `enum` takes the allowed values or a BackedEnum class; `enumName` names the PostgreSQL enum type
 * (default "{table}_{column}_enum", like TypeORM); MySQL/MariaDB use ENUM(...), SQLite a VARCHAR with a CHECK.
 * `charset` applies to MySQL/MariaDB, `collation` to MySQL/MariaDB, PostgreSQL and SQLite.
 *
 * More TypeORM column options:
 *
 *   #[Column(transformer: Lowercase::class)] public string $email;            // ValueTransformer(s): class, instance or list
 *   #[Column(type: 'money')] public Money $price;                              // custom type (Types\TypeRegistry)
 *   #[Column(type: 'enum')] public Status $status;  #[Column(type: 'simple-enum')] // backed enum: native ENUM on MySQL/MariaDB,
 *                                                                              // a PostgreSQL type ("{table}_{column}_enum" or enumName), CHECK on SQLite
 *   #[Column(select: false)] public string $password;                          // only read when selected explicitly
 *   #[Column(insert: false)] / #[Column(update: false)] / #[Column(readonly: true)] // never written by INSERT / UPDATE
 *   #[Column(type: 'simple-array')] public array $roles;                       // "a,b,c" in a TEXT column
 *   #[Column(type: 'simple-json')] public array $prefs;                        // JSON in a TEXT column
 *   #[Column(type: 'int', array: true)] public array $scores;                 // PostgreSQL INTEGER[]
 *   #[Column(generatedType: 'STORED', asExpression: "first_name || ' ' || last_name")] public ?string $fullName;
 *
 * Generated columns are computed by the database (never written, read back after save()); PostgreSQL only
 * has STORED ones (VIRTUAL needs PostgreSQL 18), MySQL/MariaDB/SQLite both. Write the expression in the
 * dialect of your database (MySQL: CONCAT(...)).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Column
{
    /**
     * @param list<string|int>|class-string<\BackedEnum>|null $enum
     * @param class-string<\Nesto\Orm\ValueTransformer>|\Nesto\Orm\ValueTransformer|list<class-string<\Nesto\Orm\ValueTransformer>|\Nesto\Orm\ValueTransformer>|null $transformer
     */
    public function __construct(
        public readonly ?string $type = null,
        public readonly ?string $name = null,
        public readonly bool $nullable = false,
        public readonly mixed $default = null,
        public readonly ?int $length = null,
        public readonly bool $unique = false,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly bool $hasDefault = false,
        public readonly ?string $comment = null,
        public readonly bool $unsigned = false,
        public readonly array|string|null $enum = null,
        public readonly ?string $enumName = null,
        public readonly ?string $charset = null,
        public readonly ?string $collation = null,
        public readonly bool $primary = false,
        public readonly string|object|array|null $transformer = null,
        public readonly bool $select = true,
        public readonly bool $insert = true,
        public readonly bool $update = true,
        public readonly bool $readonly = false,
        public readonly bool $array = false,
        public readonly ?string $generatedType = null,
        public readonly ?string $asExpression = null,
    ) {}
}
