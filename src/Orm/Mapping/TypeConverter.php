<?php

declare(strict_types=1);

namespace Nesto\Orm\Mapping;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use Nesto\Orm\Types\TypeRegistry;

/**
 * Converts values between PHP and database representations for a column: value transformers (TypeORM:
 * `to` first on write, `from` last on read), custom types (Types\TypeRegistry), then the built-in types.
 *
 *   simple-array  "a,b,c" in a TEXT column ↔ ['a', 'b', 'c'] (TypeORM: values must not contain commas)
 *   simple-json   JSON text in a TEXT column ↔ array
 *   array: true   PostgreSQL array literal ↔ list of the element type
 */
final class TypeConverter
{
    public const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public static function toPhp(ColumnMetadata $column, mixed $value): mixed
    {
        $value = self::basicToPhp($column, $value);
        foreach (array_reverse($column->transformers) as $transformer) {
            $value = $transformer->from($value);
        }

        return $value;
    }

    /** @param bool $transform false: skip value transformers (attribute defaults are database values) */
    public static function toDatabase(ColumnMetadata $column, mixed $value, bool $transform = true): mixed
    {
        if ($transform) {
            $value = self::transform($column, $value);
        }
        if ($value === null) {
            return null;
        }
        if ($column->array && is_array($value)) {
            return PgArray::format(array_map(fn (mixed $v) => self::elementToDatabase($column, $v), array_values($value)));
        }
        if (($type = TypeRegistry::get($column->type)) !== null) {
            return $type->convertToDatabaseValue($value);
        }

        return self::elementToDatabase($column, $value);
    }

    /** Only the value transformers' `to()` (LIKE patterns, raw operands). */
    public static function transform(ColumnMetadata $column, mixed $value): mixed
    {
        foreach ($column->transformers as $transformer) {
            $value = $transformer->to($value);
        }

        return $value;
    }

    /** Generic conversion for values that are not bound to a known column (raw query parameters). */
    public static function toParameter(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(self::DATETIME_FORMAT),
            is_array($value) => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            default => $value,
        };
    }

    /** Infer the column type from a PHP property type. Returns [type, enumClass]. */
    public static function inferType(?string $phpType): array
    {
        if ($phpType === null) {
            return ['string', null];
        }
        if (is_subclass_of($phpType, BackedEnum::class)) {
            $backing = (new \ReflectionEnum($phpType))->getBackingType()?->getName();

            return [$backing === 'int' ? 'int' : 'string', $phpType];
        }

        return [match ($phpType) {
            'int' => 'int',
            'float' => 'float',
            'bool' => 'bool',
            'array' => 'json',
            DateTimeImmutable::class, DateTimeInterface::class, \DateTime::class => 'datetime',
            default => 'string',
        }, null];
    }

    private static function basicToPhp(ColumnMetadata $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_resource($value)) {
            $value = (string) stream_get_contents($value); // PostgreSQL BYTEA / LOBs come as streams
        }
        if ($column->array) {
            $items = is_string($value) ? PgArray::parse($value) : (array) $value;

            return array_map(fn (mixed $v) => self::elementToPhp($column, $v), $items);
        }
        if (($type = TypeRegistry::get($column->type)) !== null) {
            return $type->convertToPHPValue($value);
        }

        return self::elementToPhp($column, $value);
    }

    private static function elementToPhp(ColumnMetadata $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($column->enumClass !== null) {
            return $value instanceof BackedEnum ? $value : $column->enumClass::from(self::castBacking($column->enumClass, $value));
        }

        return match ($column->type) {
            'int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint' => (int) $value,
            'float', 'double', 'real', 'double precision' => (float) $value,
            // MySQL/MariaDB/PostgreSQL return DECIMAL as an exact string ("9.50"); SQLite returns a number:
            // format it with the column scale so a string property reads the same on every driver
            'decimal', 'numeric' => $column->phpType === 'float'
                ? (float) $value
                : (is_int($value) || is_float($value) ? number_format((float) $value, $column->scale ?? 2, '.', '') : (string) $value),
            'bool', 'boolean' => is_string($value) ? in_array(strtolower($value), ['1', 't', 'true'], true) : (bool) $value,
            'datetime', 'timestamp', 'timestamptz', 'date', 'time' => $value instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($value)
                : new DateTimeImmutable((string) $value),
            'json', 'jsonb', 'simple-json' => is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value,
            'simple-array' => is_array($value) ? $value : ((string) $value === '' ? [] : explode(',', (string) $value)),
            default => is_scalar($value) ? (string) $value : $value,
        };
    }

    private static function elementToDatabase(ColumnMetadata $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return match ($column->type) {
            // enum labels are strings (MySQL reads an integer as the position of a label)
            'enum', 'simple-enum' => is_scalar($value) ? (string) $value : $value,
            'int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint' => (int) $value,
            'float', 'double', 'real', 'double precision' => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'datetime', 'timestamp', 'timestamptz' => self::formatDate($value, self::DATETIME_FORMAT),
            'date' => self::formatDate($value, 'Y-m-d'),
            'time' => self::formatDate($value, 'H:i:s'),
            'json', 'jsonb', 'simple-json' => is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'simple-array' => is_array($value) ? implode(',', array_map(fn (mixed $v) => $v instanceof BackedEnum ? $v->value : (string) $v, $value)) : (string) $value,
            default => is_scalar($value) ? $value : (string) $value,
        };
    }

    /** @param class-string<BackedEnum> $enum */
    private static function castBacking(string $enum, mixed $value): int|string
    {
        return (new \ReflectionEnum($enum))->getBackingType()?->getName() === 'int' ? (int) $value : (string) $value;
    }

    private static function formatDate(mixed $value, string $format): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format($format);
        }

        return (new DateTimeImmutable((string) $value))->format($format);
    }
}
