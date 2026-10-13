<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema;

/**
 * Scalar types, their default database types and the `@db.*` native types of each provider.
 *
 *   scalar     postgresql          mysql            sqlite
 *   String     TEXT                VARCHAR(191)     TEXT
 *   Boolean    BOOLEAN             TINYINT(1)       BOOLEAN
 *   Int        INTEGER (SERIAL)    INTEGER          INTEGER
 *   BigInt     BIGINT (BIGSERIAL)  BIGINT           BIGINT
 *   Float      DOUBLE PRECISION    DOUBLE           REAL
 *   Decimal    DECIMAL(65,30)      DECIMAL(65,30)   DECIMAL
 *   DateTime   TIMESTAMP(3)        DATETIME(3)      DATETIME
 *   Json       JSONB               JSON             JSONB
 *   Bytes      BYTEA               LONGBLOB         BLOB
 */
final class NativeTypes
{
    public const SCALARS = ['String', 'Boolean', 'Int', 'BigInt', 'Float', 'Decimal', 'DateTime', 'Json', 'Bytes'];

    /** name => [compatible scalars, min args, max args] */
    private const POSTGRESQL = [
        'Text' => [['String'], 0, 0],
        'Char' => [['String'], 0, 1],
        'VarChar' => [['String'], 0, 1],
        'Bit' => [['String'], 0, 1],
        'VarBit' => [['String'], 0, 1],
        'Uuid' => [['String'], 0, 0],
        'Xml' => [['String'], 0, 0],
        'Inet' => [['String'], 0, 0],
        'Citext' => [['String'], 0, 0],
        'Boolean' => [['Boolean'], 0, 0],
        'Integer' => [['Int'], 0, 0],
        'SmallInt' => [['Int'], 0, 0],
        'Oid' => [['Int'], 0, 0],
        'BigInt' => [['BigInt'], 0, 0],
        'DoublePrecision' => [['Float'], 0, 0],
        'Real' => [['Float'], 0, 0],
        'Decimal' => [['Decimal'], 0, 2],
        'Money' => [['Decimal'], 0, 0],
        'Timestamp' => [['DateTime'], 0, 1],
        'Timestamptz' => [['DateTime'], 0, 1],
        'Date' => [['DateTime'], 0, 0],
        'Time' => [['DateTime'], 0, 1],
        'Timetz' => [['DateTime'], 0, 1],
        'Json' => [['Json'], 0, 0],
        'JsonB' => [['Json'], 0, 0],
        'ByteA' => [['Bytes'], 0, 0],
    ];

    private const MYSQL = [
        'VarChar' => [['String'], 1, 1],
        'Text' => [['String'], 0, 0],
        'Char' => [['String'], 0, 1],
        'TinyText' => [['String'], 0, 0],
        'MediumText' => [['String'], 0, 0],
        'LongText' => [['String'], 0, 0],
        'Bit' => [['Boolean', 'Bytes'], 0, 1],
        'TinyInt' => [['Int', 'Boolean'], 0, 1],
        'UnsignedTinyInt' => [['Int', 'Boolean'], 0, 1],
        'Int' => [['Int'], 0, 0],
        'UnsignedInt' => [['Int'], 0, 0],
        'SmallInt' => [['Int'], 0, 0],
        'UnsignedSmallInt' => [['Int'], 0, 0],
        'MediumInt' => [['Int'], 0, 0],
        'UnsignedMediumInt' => [['Int'], 0, 0],
        'Year' => [['Int'], 0, 0],
        'BigInt' => [['BigInt'], 0, 0],
        'UnsignedBigInt' => [['BigInt'], 0, 0],
        'Float' => [['Float'], 0, 0],
        'Double' => [['Float'], 0, 0],
        'Decimal' => [['Decimal'], 0, 2],
        'DateTime' => [['DateTime'], 0, 1],
        'Date' => [['DateTime'], 0, 0],
        'Time' => [['DateTime'], 0, 1],
        'Timestamp' => [['DateTime'], 0, 1],
        'Json' => [['Json'], 0, 0],
        'Binary' => [['Bytes'], 0, 1],
        'VarBinary' => [['Bytes'], 1, 1],
        'TinyBlob' => [['Bytes'], 0, 0],
        'Blob' => [['Bytes'], 0, 0],
        'MediumBlob' => [['Bytes'], 0, 0],
        'LongBlob' => [['Bytes'], 0, 0],
    ];

    /** @return array<string, array{0: list<string>, 1: int, 2: int}> */
    public static function catalog(string $provider): array
    {
        return match ($provider) {
            'postgresql' => self::POSTGRESQL,
            'mysql' => self::MYSQL,
            default => [],
        };
    }

    /** @return array{0: list<string>, 1: int, 2: int}|null */
    public static function definition(string $provider, string $name): ?array
    {
        return self::catalog($provider)[$name] ?? null;
    }

    /** The native type a scalar gets without @db.* (Prisma's defaults). @return array{0: string, 1: list<string>} */
    public static function defaultFor(string $scalar, string $provider): array
    {
        return match ($provider) {
            'postgresql' => match ($scalar) {
                'String' => ['Text', []],
                'Boolean' => ['Boolean', []],
                'Int' => ['Integer', []],
                'BigInt' => ['BigInt', []],
                'Float' => ['DoublePrecision', []],
                'Decimal' => ['Decimal', ['65', '30']],
                'DateTime' => ['Timestamp', ['3']],
                'Json' => ['JsonB', []],
                'Bytes' => ['ByteA', []],
                default => [$scalar, []],
            },
            'mysql' => match ($scalar) {
                'String' => ['VarChar', ['191']],
                'Boolean' => ['TinyInt', ['1']],
                'Int' => ['Int', []],
                'BigInt' => ['BigInt', []],
                'Float' => ['Double', []],
                'Decimal' => ['Decimal', ['65', '30']],
                'DateTime' => ['DateTime', ['3']],
                'Json' => ['Json', []],
                'Bytes' => ['LongBlob', []],
                default => [$scalar, []],
            },
            default => match ($scalar) {
                'String' => ['TEXT', []],
                'Boolean' => ['BOOLEAN', []],
                'Int' => ['INTEGER', []],
                'BigInt' => ['BIGINT', []],
                'Float' => ['REAL', []],
                'Decimal' => ['DECIMAL', []],
                'DateTime' => ['DATETIME', []],
                'Json' => ['JSONB', []],
                'Bytes' => ['BLOB', []],
                default => [$scalar, []],
            },
        };
    }

    /**
     * SQL declaration of a native type ("VARCHAR(255)", "DOUBLE PRECISION", "INT UNSIGNED", "TIMESTAMP(3)").
     *
     * @param list<string> $args
     */
    public static function sql(string $name, array $args, string $provider): string
    {
        $params = $args !== [] ? '(' . implode(',', $args) . ')' : '';
        if ($provider === 'sqlite') {
            return strtoupper($name) . $params;
        }
        if ($provider === 'postgresql') {
            return match ($name) {
                'DoublePrecision' => 'DOUBLE PRECISION',
                'Timestamptz' => 'TIMESTAMPTZ' . $params,
                'Timetz' => 'TIMETZ' . $params,
                'JsonB' => 'JSONB',
                'ByteA' => 'BYTEA',
                'VarBit' => 'VARBIT' . $params,
                'Decimal' => 'DECIMAL' . ($args === [] ? '(65,30)' : $params),
                default => strtoupper($name) . $params,
            };
        }

        return match ($name) {
            'UnsignedInt' => 'INT UNSIGNED',
            'UnsignedSmallInt' => 'SMALLINT UNSIGNED',
            'UnsignedMediumInt' => 'MEDIUMINT UNSIGNED',
            'UnsignedTinyInt' => 'TINYINT' . $params . ' UNSIGNED',
            'UnsignedBigInt' => 'BIGINT UNSIGNED',
            'Int' => $args === [] ? 'INTEGER' : 'INT' . $params,
            'Decimal' => 'DECIMAL' . ($args === [] ? '(65,30)' : $params),
            default => strtoupper($name) . $params,
        };
    }

    /**
     * The Nesto abstract column type for a field (what Grammar uses for implicit defaults and literal
     * quoting): string, text, int, bigint, double, decimal, boolean, datetime, json, blob.
     */
    public static function abstractType(string $scalar, ?string $nativeName): string
    {
        if ($nativeName !== null && in_array($nativeName, ['Text', 'TinyText', 'MediumText', 'LongText', 'Citext', 'Xml'], true)) {
            return 'text';
        }
        if ($nativeName !== null && in_array($nativeName, ['Date'], true)) {
            return 'date';
        }

        return match ($scalar) {
            'String' => 'string',
            'Boolean' => 'boolean',
            'Int' => 'int',
            'BigInt' => 'bigint',
            'Float' => 'double',
            'Decimal' => 'decimal',
            'DateTime' => 'datetime',
            'Json' => 'json',
            'Bytes' => 'blob',
            default => 'string',
        };
    }
}
