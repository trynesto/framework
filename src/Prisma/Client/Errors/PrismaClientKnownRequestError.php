<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Errors;

use Nesto\Prisma\PrismaException;

/**
 * Prisma's `PrismaClientKnownRequestError`: a query failed for a known reason, identified by a Prisma error code
 * (`getCode()` / `$e->code` is the string "P2002", like PDOException's SQLSTATE) with details in `meta`.
 *
 *   try {
 *       $prisma->user->create(['data' => ['email' => 'taken@example.com']]);
 *   } catch (UniqueConstraintError $e) {              // or PrismaClientKnownRequestError + $e->getCode() === 'P2002'
 *       $e->meta['target'];                           // ['email']
 *   }
 *
 * Subclasses per code: P2000 ValueTooLongError, P2002 UniqueConstraintError, P2003 ForeignKeyConstraintError,
 * P2010 RawQueryError, P2011 NullConstraintError, P2014 RequiredRelationViolationError, P2025
 * RecordNotFoundError, P2028 TransactionError, P2034 WriteConflictError. Other codes use this class.
 */
class PrismaClientKnownRequestError extends PrismaException
{
    /** code => subclass */
    private const CLASSES = [
        'P2000' => ValueTooLongError::class,
        'P2002' => UniqueConstraintError::class,
        'P2003' => ForeignKeyConstraintError::class,
        'P2010' => RawQueryError::class,
        'P2011' => NullConstraintError::class,
        'P2014' => RequiredRelationViolationError::class,
        'P2025' => RecordNotFoundError::class,
        'P2028' => TransactionError::class,
        'P2034' => WriteConflictError::class,
    ];

    public const CLIENT_VERSION = 'nesto-1.0';

    /** @param array<string, mixed> $meta */
    final public function __construct(
        string $message,
        string $code,
        public readonly array $meta = [],
        ?\Throwable $previous = null,
        public readonly string $clientVersion = self::CLIENT_VERSION,
    ) {
        parent::__construct($message, 0, $previous);
        $this->code = $code; // like PDOException: the code is a string
    }

    /** The error of a code, as its subclass when there is one. @param array<string, mixed> $meta */
    public static function create(string $code, string $message, array $meta = [], ?\Throwable $previous = null): self
    {
        $class = self::CLASSES[$code] ?? self::class;

        return new $class($message, $code, $meta, $previous);
    }

    /** The Prisma error code ("P2002"). */
    public function errorCode(): string
    {
        return (string) $this->code;
    }
}
