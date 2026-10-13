<?php

declare(strict_types=1);

namespace Nesto\Orm;

/** A statement failed. Carries the SQL and parameters for debugging. */
class QueryException extends OrmException
{
    /** @param array<string|int, mixed> $params */
    public function __construct(string $message, public readonly string $sql, public readonly array $params, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
