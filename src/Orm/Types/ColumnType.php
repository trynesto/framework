<?php

declare(strict_types=1);

namespace Nesto\Orm\Types;

use Nesto\Orm\Schema\Column;

/**
 * A custom column type (Doctrine's Type): how values convert and which SQL type declares the column.
 * Register it once (bootstrap / module setup), then use its name as #[Column(type: ...)]:
 *
 *   final class MoneyType extends ColumnType {
 *       public function sqlDeclaration(Column $column, string $platform): string { return 'DECIMAL(19, 4)'; }
 *       public function convertToDatabaseValue(mixed $value): mixed { return $value->amount; }
 *       public function convertToPHPValue(mixed $value): mixed { return new Money((string) $value); }
 *   }
 *   TypeRegistry::register('money', MoneyType::class);       // or DataSource option 'types' => ['money' => MoneyType::class]
 *   #[Column(type: 'money')] public Money $price;
 *
 * Conversions never receive null (null stays null). `$platform` is sqlite, mysql, mariadb or pgsql.
 * Schema comparison normalizes the declared SQL like any type ("DECIMAL(19, 4)" equals PostgreSQL's
 * "numeric(19,4)"); override normalizeDatabaseType() when the database reports the type differently.
 */
abstract class ColumnType
{
    /** The SQL type of the column ("DECIMAL(19, 4)", "INET", "VARCHAR(3)"), without NULL / DEFAULT. */
    abstract public function sqlDeclaration(Column $column, string $platform): string;

    /** PHP value → value bound to the statement. */
    public function convertToDatabaseValue(mixed $value): mixed
    {
        return $value;
    }

    /** Value as the driver returned it → PHP value. */
    public function convertToPHPValue(mixed $value): mixed
    {
        return $value;
    }

    /**
     * Comparable form of an SQL type string of this column, applied to both the declared and the
     * introspected type before they are compared (both already in the canonical form of Normalizer::type()).
     */
    public function normalizeDatabaseType(string $canonicalType, string $platform): string
    {
        return $canonicalType;
    }

    /** SQL literal that fills existing rows when a NOT NULL column of this type is added (null: none). */
    public function implicitDefault(string $platform): ?string
    {
        return null;
    }
}
