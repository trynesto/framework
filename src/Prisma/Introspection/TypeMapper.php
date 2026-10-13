<?php

declare(strict_types=1);

namespace Nesto\Prisma\Introspection;

use Nesto\Orm\Schema\Column;
use Nesto\Orm\Schema\Normalizer;

/**
 * Database column type → Prisma scalar type plus the `@db.*` native type when it is not the scalar's default
 * (PostgreSQL TEXT is `String`, VARCHAR(255) is `String @db.VarChar(255)`, TIMESTAMP(6) is
 * `DateTime @db.Timestamp(6)`; MySQL VARCHAR(191) is `String`, TINYINT(1) is `Boolean`, ...). Types Prisma has
 * no scalar for become `Unsupported("...")`.
 */
final class TypeMapper
{
    /**
     * @return array{type: string, native: ?array{0: string, 1: list<string>}, unsupported: ?string}
     *         type "Unsupported" with `unsupported` set to the raw type when nothing fits
     */
    public static function map(Column $column, string $platform): array
    {
        $raw = (string) ($column->rawType ?? $column->type);
        if ($platform === 'sqlite') {
            return self::sqlite($raw);
        }
        [$canonical, $unsigned] = Normalizer::type($platform, $raw);
        if (str_ends_with($canonical, '[]')) {
            $canonical = substr($canonical, 0, -2);
        }
        preg_match('/^([a-z0-9_ ]+)(?:\((.*)\))?$/', $canonical, $m);
        $base = $m[1] ?? $canonical;
        $args = isset($m[2]) && $m[2] !== '' ? array_map('trim', explode(',', $m[2])) : [];

        return $platform === 'pgsql' ? self::postgres($base, $args, $raw) : self::mysql($base, $args, $unsigned, $raw, $column);
    }

    /** @param list<string> $args */
    private static function postgres(string $base, array $args, string $raw): array
    {
        $scalar = fn (string $type, ?string $native = null, array $nativeArgs = []) => ['type' => $type, 'native' => $native !== null ? [$native, $nativeArgs] : null, 'unsupported' => null];

        return match ($base) {
            'int' => $scalar('Int'),
            'smallint' => $scalar('Int', 'SmallInt'),
            'oid' => $scalar('Int', 'Oid'),
            'bigint' => $scalar('BigInt'),
            'text' => $scalar('String'),
            'varchar' => $scalar('String', 'VarChar', $args),
            'char', 'bpchar' => $scalar('String', 'Char', $args),
            'uuid' => $scalar('String', 'Uuid'),
            'xml' => $scalar('String', 'Xml'),
            'inet' => $scalar('String', 'Inet'),
            'citext' => $scalar('String', 'Citext'),
            'bit' => $scalar('String', 'Bit', $args),
            'varbit', 'bitvarying' => $scalar('String', 'VarBit', $args),
            'boolean' => $scalar('Boolean'),
            'double' => $scalar('Float'),
            'real' => $scalar('Float', 'Real'),
            'numeric' => $args === ['65', '30'] ? $scalar('Decimal') : $scalar('Decimal', 'Decimal', $args),
            'money' => $scalar('Decimal', 'Money'),
            'timestamp' => $args === ['3'] ? $scalar('DateTime') : $scalar('DateTime', 'Timestamp', $args === [] ? ['6'] : $args),
            'timestamptz' => $scalar('DateTime', 'Timestamptz', $args === [] ? ['6'] : $args),
            'date' => $scalar('DateTime', 'Date'),
            'time' => $scalar('DateTime', 'Time', $args === [] ? ['6'] : $args),
            'timetz' => $scalar('DateTime', 'Timetz', $args === [] ? ['6'] : $args),
            'jsonb' => $scalar('Json'),
            'json' => $scalar('Json', 'Json'),
            'bytea' => $scalar('Bytes'),
            default => ['type' => 'Unsupported', 'native' => null, 'unsupported' => preg_replace('/\[\]$/', '', $raw)],
        };
    }

    /** @param list<string> $args */
    private static function mysql(string $base, array $args, bool $unsigned, string $raw, Column $column): array
    {
        $scalar = fn (string $type, ?string $native = null, array $nativeArgs = []) => ['type' => $type, 'native' => $native !== null ? [$native, $nativeArgs] : null, 'unsupported' => null];
        if ($column->type === 'json') {
            return $scalar('Json'); // MariaDB JSON (LONGTEXT + json_valid check)
        }

        return match (true) {
            $base === 'tinyint' && $args === ['1'] => $scalar('Boolean'),
            $base === 'int' => $unsigned ? $scalar('Int', 'UnsignedInt') : $scalar('Int'),
            $base === 'smallint' => $scalar('Int', $unsigned ? 'UnsignedSmallInt' : 'SmallInt'),
            $base === 'mediumint' => $scalar('Int', $unsigned ? 'UnsignedMediumInt' : 'MediumInt'),
            $base === 'tinyint' => $scalar('Int', $unsigned ? 'UnsignedTinyInt' : 'TinyInt'),
            $base === 'year' => $scalar('Int', 'Year'),
            $base === 'bigint' => $unsigned ? $scalar('BigInt', 'UnsignedBigInt') : $scalar('BigInt'),
            $base === 'varchar' => $args === ['191'] ? $scalar('String') : $scalar('String', 'VarChar', $args),
            $base === 'char' => $scalar('String', 'Char', $args),
            $base === 'text' => $scalar('String', 'Text'),
            $base === 'tinytext' => $scalar('String', 'TinyText'),
            $base === 'mediumtext' => $scalar('String', 'MediumText'),
            $base === 'longtext' => $scalar('String', 'LongText'),
            $base === 'double' => $scalar('Float'),
            $base === 'float' => $scalar('Float', 'Float'),
            $base === 'decimal' => $args === ['65', '30'] ? $scalar('Decimal') : $scalar('Decimal', 'Decimal', $args),
            $base === 'datetime' => $args === ['3'] ? $scalar('DateTime') : $scalar('DateTime', 'DateTime', $args === [] ? ['0'] : $args),
            $base === 'timestamp' => $scalar('DateTime', 'Timestamp', $args === [] ? ['0'] : $args),
            $base === 'date' => $scalar('DateTime', 'Date'),
            $base === 'time' => $scalar('DateTime', 'Time', $args === [] ? ['0'] : $args),
            $base === 'json' => $scalar('Json'),
            $base === 'longblob' => $scalar('Bytes'),
            $base === 'blob' => $scalar('Bytes', 'Blob'),
            $base === 'tinyblob' => $scalar('Bytes', 'TinyBlob'),
            $base === 'mediumblob' => $scalar('Bytes', 'MediumBlob'),
            $base === 'binary' => $scalar('Bytes', 'Binary', $args),
            $base === 'varbinary' => $scalar('Bytes', 'VarBinary', $args),
            $base === 'bit' => $args === ['1'] || $args === [] ? $scalar('Boolean', 'Bit', ['1']) : $scalar('Bytes', 'Bit', $args),
            default => ['type' => 'Unsupported', 'native' => null, 'unsupported' => $raw],
        };
    }

    private static function sqlite(string $raw): array
    {
        $type = strtolower(trim($raw));
        $base = (string) preg_replace('/\(.*$/', '', $type);
        $scalar = fn (string $t) => ['type' => $t, 'native' => null, 'unsupported' => null];

        return match (true) {
            in_array($base, ['integer', 'int', 'smallint', 'tinyint', 'mediumint'], true) => $scalar('Int'),
            $base === 'bigint' || $base === 'unsigned big int' => $scalar('BigInt'),
            in_array($base, ['text', 'varchar', 'char', 'clob', 'character', 'nvarchar', 'nchar', 'varying character', 'native character'], true) => $scalar('String'),
            in_array($base, ['boolean', 'bool'], true) => $scalar('Boolean'),
            in_array($base, ['real', 'double', 'double precision', 'float'], true) => $scalar('Float'),
            in_array($base, ['decimal', 'numeric'], true) => $scalar('Decimal'),
            in_array($base, ['datetime', 'date', 'timestamp'], true) => $scalar('DateTime'),
            in_array($base, ['jsonb', 'json'], true) => $scalar('Json'),
            $base === 'blob' => $scalar('Bytes'),
            default => ['type' => 'Unsupported', 'native' => null, 'unsupported' => $raw],
        };
    }
}
