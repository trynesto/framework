<?php

declare(strict_types=1);

namespace Nesto\Orm\Types;

/**
 * Raw bytes bound as a binary parameter (PDO::PARAM_LOB): BYTEA on PostgreSQL, BLOB on MySQL / MariaDB /
 * SQLite. A plain PHP string is bound as text, which PostgreSQL rejects for non-UTF-8 bytes.
 *
 *   $connection->execute('INSERT INTO files (data) VALUES (?)', [new Binary($bytes)]);
 */
final class Binary
{
    public function __construct(public readonly string $bytes) {}
}
