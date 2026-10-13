<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Internal;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Nesto\Orm\Mapping\PgArray;
use Nesto\Orm\Types\Binary;
use Nesto\Prisma\Client\Errors\PrismaClientValidationError;
use Nesto\Prisma\Client\Prisma;
use Nesto\Prisma\Dmmf\Document;
use Nesto\Prisma\Dmmf\Field;
use UnitEnum;

/**
 * PHP ↔ database values per Prisma scalar type:
 *
 *   DateTime  DateTimeImmutable in UTC (written as UTC; strings are parsed)
 *   Decimal   string, normalized ("9.99", trailing zeros removed) — exact, unlike float
 *   BigInt    int, or a numeric string beyond PHP_INT_MAX
 *   Bytes     string (bound as binary)
 *   Json      decoded PHP value (arrays for objects); Prisma::JsonNull stores JSON null
 *   enums     the generated PHP backed enum when the client was generated (the case value is the enum value
 *             name of the schema), else the name as a string; @map'ed database values are translated
 *
 * @internal
 */
final class ValueCodec
{
    private static ?DateTimeZone $utc = null;

    /** @param array<string, class-string<BackedEnum>> $enumClasses enum name => generated PHP enum */
    public function __construct(
        private readonly string $platform,
        private readonly Document $document,
        private readonly array $enumClasses = [],
    ) {}

    /** A value to bind for a field (whole list for list fields). */
    public function encode(Field $field, mixed $value): mixed
    {
        if ($value === null || $value === Prisma::DbNull) {
            return null;
        }
        if ($field->isList) {
            if (! is_array($value)) {
                $value = [$value];
            }

            return $this->encodeList($field, $value);
        }

        return $this->encodeScalar($field, $value);
    }

    /** @param array<mixed> $values a PostgreSQL array literal for a scalar list */
    public function encodeList(Field $field, array $values): string
    {
        return PgArray::format(array_map(function (mixed $v) use ($field) {
            $encoded = $this->encodeScalar($field, $v);

            return $encoded instanceof Binary ? '\\x' . bin2hex($encoded->bytes) : $encoded;
        }, array_values($values)));
    }

    /** One element (lists: one item). */
    public function encodeScalar(Field $field, mixed $value): mixed
    {
        if ($value === null || $value === Prisma::DbNull) {
            return null;
        }
        if ($field->kind === Field::ENUM) {
            return $this->enumToDatabase($field, $value);
        }

        return match ($field->type) {
            'String' => $this->string($field, $value),
            'Int' => $this->int($field, $value),
            'BigInt' => is_int($value) ? $value : (is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (PHP_INT_SIZE >= 8 && (string) (int) $value === $value ? (int) $value : $value) : $this->int($field, $value)),
            'Float' => is_int($value) || is_float($value) || is_numeric($value) ? (float) $value : $this->invalid($field, $value, 'Float'),
            'Decimal' => $this->decimalIn($field, $value),
            'Boolean' => is_bool($value) ? $value : (in_array($value, [0, 1, '0', '1'], true) ? (bool) $value : $this->invalid($field, $value, 'Boolean')),
            'DateTime' => $this->dateTimeIn($field, $value),
            'Json' => $this->jsonIn($value),
            'Bytes' => $value instanceof Binary ? $value : (is_string($value) ? new Binary($value) : $this->invalid($field, $value, 'Bytes')),
            default => $value,
        };
    }

    /** JSON text of a Json value. */
    public function jsonIn(mixed $value): string
    {
        if ($value === Prisma::JsonNull || $value === Prisma::AnyNull) {
            return 'null';
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function decode(Field $field, mixed $value): mixed
    {
        if ($value === null) {
            return $field->isList ? [] : null; // a scalar list is never null in the client
        }
        if (is_resource($value)) {
            $value = (string) stream_get_contents($value);
        }
        if ($field->isList) {
            $items = is_array($value) ? $value : PgArray::parse((string) $value);

            return array_map(fn (mixed $item) => $item === null ? null : $this->decodeScalar($field, $item, true), $items);
        }

        return $this->decodeScalar($field, $value);
    }

    private function decodeScalar(Field $field, mixed $value, bool $fromArray = false): mixed
    {
        if ($field->kind === Field::ENUM) {
            return $this->enumFromDatabase($field, (string) $value);
        }

        return match ($field->type) {
            'String' => (string) $value,
            'Int' => (int) $value,
            'BigInt' => is_int($value) ? $value : ((string) (int) $value === (string) $value ? (int) $value : (string) $value),
            'Float' => (float) $value,
            'Decimal' => self::normalizeDecimal((string) $value),
            'Boolean' => is_bool($value) ? $value : in_array(strtolower((string) $value), ['1', 't', 'true'], true),
            'DateTime' => $this->dateTimeOut($value),
            'Json' => is_string($value) ? json_decode($value, true, 512, JSON_BIGINT_AS_STRING) : $value,
            'Bytes' => $fromArray && str_starts_with((string) $value, '\\x') ? (string) hex2bin(substr((string) $value, 2)) : (string) $value,
            default => $value,
        };
    }

    /** "9.990000" → "9.99", "10.000" → "10", "-0.50" → "-0.5", "1e3" → "1000". */
    public static function normalizeDecimal(string $value): string
    {
        $value = trim($value);
        if (stripos($value, 'e') !== false && is_numeric($value)) {
            $value = rtrim(rtrim(number_format((float) $value, 20, '.', ''), '0'), '.');
        }
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        if ($value === '-0' || $value === '' || $value === '-') {
            return '0';
        }
        if (str_starts_with($value, '.')) {
            $value = '0' . $value;
        } elseif (str_starts_with($value, '-.')) {
            $value = '-0' . substr($value, 1);
        }

        return $value;
    }

    private function decimalIn(Field $field, mixed $value): string
    {
        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }
        if (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.15F', $value), '0'), '.');
        }
        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            return self::normalizeDecimal((string) $value);
        }

        return $this->invalid($field, $value, 'Decimal');
    }

    private function string(Field $field, mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value instanceof \Stringable || is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return $this->invalid($field, $value, 'String');
    }

    private function int(Field $field, mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if ((is_string($value) && preg_match('/^-?\d+$/', $value) === 1) || (is_float($value) && floor($value) === $value)) {
            return (int) $value;
        }

        return $this->invalid($field, $value, $field->type);
    }

    public static function utc(): DateTimeZone
    {
        return self::$utc ??= new DateTimeZone('UTC');
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::utc());
    }

    private function dateTimeIn(Field $field, mixed $value): string
    {
        if (is_string($value)) {
            try {
                $value = new DateTimeImmutable($value, self::utc());
            } catch (\Exception) {
                $this->invalid($field, $value, 'DateTime');
            }
        } elseif (is_int($value)) {
            $value = (new DateTimeImmutable('@' . $value));
        }
        if (! $value instanceof DateTimeInterface) {
            $this->invalid($field, $value, 'DateTime');
        }
        $utc = DateTimeImmutable::createFromInterface($value)->setTimezone(self::utc());
        $native = strtolower($field->nativeType[0] ?? '');
        if ($native === 'date') {
            return $utc->format('Y-m-d');
        }
        if ($native === 'time' || $native === 'timetz') {
            return $utc->format('H:i:s.v');
        }

        return $this->platform === 'pgsql' ? $utc->format('Y-m-d H:i:s.vP') : $utc->format('Y-m-d H:i:s.v');
    }

    private function dateTimeOut(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(self::utc());
        }
        $string = trim((string) $value);
        if (is_numeric($string)) {
            // epoch milliseconds (how Prisma itself stores DateTime in SQLite)
            return (new DateTimeImmutable('@' . sprintf('%.3F', ((float) $string) / 1000)))->setTimezone(self::utc());
        }
        if (preg_match('/^\d{2}:\d{2}/', $string) === 1) {
            $string = '1970-01-01 ' . $string; // TIME columns
        }
        // PostgreSQL "2024-01-01 10:00:00+00", "+05:30"
        $string = (string) preg_replace('/([+-]\d{2})$/', '$1:00', $string);

        return (new DateTimeImmutable($string, self::utc()))->setTimezone(self::utc());
    }

    private function enumToDatabase(Field $field, mixed $value): string
    {
        $name = match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof UnitEnum => $value->name,
            default => is_string($value) ? $value : $this->invalid($field, $value, $field->type),
        };
        $enum = $this->document->enum($field->type);
        $enumValue = $enum?->value($name);
        if ($enumValue === null) {
            $allowed = $enum !== null ? implode(', ', array_map(fn ($v) => $v->name, $enum->values)) : '';
            throw new PrismaClientValidationError(sprintf('Invalid value for argument `%s`. Expected %s (%s), got "%s".', $field->name, $field->type, $allowed, $name));
        }

        return $enumValue->databaseValue();
    }

    private function enumFromDatabase(Field $field, string $value): mixed
    {
        $enum = $this->document->enum($field->type);
        $name = $value;
        foreach ($enum?->values ?? [] as $candidate) {
            if ($candidate->databaseValue() === $value) {
                $name = $candidate->name;
                break;
            }
        }
        $class = $this->enumClasses[$field->type] ?? null;

        return $class !== null ? ($class::tryFrom($name) ?? $name) : $name;
    }

    private function invalid(Field $field, mixed $value, string $expected): never
    {
        throw new PrismaClientValidationError(sprintf(
            'Invalid value for argument `%s`: expected %s, provided %s.',
            $field->name,
            $expected,
            get_debug_type($value),
        ));
    }
}
