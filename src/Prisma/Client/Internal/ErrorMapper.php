<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Internal;

use Nesto\Orm\QueryException;
use Nesto\Prisma\Client\Errors\PrismaClientInitializationError;
use Nesto\Prisma\Client\Errors\PrismaClientKnownRequestError;
use Nesto\Prisma\Client\Errors\PrismaClientUnknownRequestError;

/**
 * Database errors → Prisma's error codes, with Prisma's messages and `meta`:
 *
 *   P2002 unique constraint (meta target: field names, modelName)   P2003 foreign key (meta field_name: "<constraint> (index)")
 *   P2011 null constraint (meta constraint)                          P2000 value too long (meta column_name)
 *   P2034 write conflict / deadlock                                  P1001 / P1003 / P1000 connection errors
 *
 * @internal
 */
final class ErrorMapper
{
    /** @param array<string, mixed> $args the operation's arguments (to tell SQLite's unnamed foreign key errors apart) */
    public static function map(\Throwable $error, DataModel $dataModel, ?string $model, string $operation, array $args = []): \Throwable
    {
        if (! $error instanceof QueryException && ! $error instanceof \PDOException) {
            return $error;
        }
        $pdo = $error instanceof \PDOException ? $error : ($error->getPrevious() instanceof \PDOException ? $error->getPrevious() : null);
        $state = (string) ($pdo?->errorInfo[0] ?? $pdo?->getCode() ?? '');
        $code = (int) ($pdo?->errorInfo[1] ?? 0);
        $message = (string) ($pdo?->errorInfo[2] ?? $pdo?->getMessage() ?? $error->getMessage());
        $prefix = sprintf("Invalid `prisma.%s.%s()` invocation:\n\n", $model !== null ? lcfirst($model) : 'client', $operation);
        $known = fn (string $prismaCode, string $text, array $meta) => PrismaClientKnownRequestError::create($prismaCode, $prefix . $text, $meta + ($model !== null ? ['modelName' => $model] : []), $error);

        // unique constraint
        if ($state === '23505' || $code === 1062 || ($code === 19 && stripos($message, 'UNIQUE constraint failed') !== false) || stripos($message, 'UNIQUE constraint failed') !== false) {
            $fields = self::uniqueFields($message, $dataModel, $model);

            return $known('P2002', sprintf('Unique constraint failed on the %s', $fields !== [] ? 'fields: (' . implode(',', array_map(fn ($f) => '`' . $f . '`', $fields)) . ')' : 'constraint'), ['target' => $fields]);
        }
        // foreign key
        if ($state === '23503' || in_array($code, [1451, 1452, 1216, 1217], true) || stripos($message, 'FOREIGN KEY constraint failed') !== false) {
            $constraint = self::foreignKeyConstraint($message, $dataModel, $model, $operation, $args);
            $field = $constraint !== null ? $constraint . ' (index)' : 'foreign key';

            return $known('P2003', sprintf('Foreign key constraint failed on the field: `%s`', $field), ['field_name' => $field]);
        }
        // not null
        if ($state === '23502' || in_array($code, [1048, 1364], true) || stripos($message, 'NOT NULL constraint failed') !== false) {
            $column = self::notNullColumn($message);
            $field = self::fieldOfColumn($dataModel, $model, $column);

            return $known('P2011', sprintf('Null constraint violation on the %s', $field !== null ? 'fields: (`' . $field . '`)' : 'constraint'), ['constraint' => $field !== null ? [$field] : []]);
        }
        // value too long
        if ($state === '22001' || $code === 1406) {
            $column = preg_match("/column '([^']+)'/i", $message, $m) === 1 ? $m[1] : null;

            return $known('P2000', 'The provided value for the column is too long for the column\'s type. Column: ' . ($column ?? '(not available)'), ['column_name' => $column]);
        }
        // write conflict / deadlock / lock timeout
        if (in_array($state, ['40001', '40P01'], true) || in_array($code, [1213, 1205], true) || stripos($message, 'database is locked') !== false) {
            return $known('P2034', 'Transaction failed due to a write conflict or a deadlock. Please retry your transaction', []);
        }

        return new PrismaClientUnknownRequestError($prefix . $message, 0, $error);
    }

    /** Raw query failure: P2010 with the database code and message. */
    public static function raw(\Throwable $error): \Throwable
    {
        if (! $error instanceof QueryException && ! $error instanceof \PDOException) {
            return $error;
        }
        $pdo = $error instanceof \PDOException ? $error : $error->getPrevious();
        $code = $pdo instanceof \PDOException ? (string) ($pdo->errorInfo[1] ?? $pdo->getCode()) : '';
        $message = $pdo instanceof \PDOException ? (string) ($pdo->errorInfo[2] ?? $pdo->getMessage()) : $error->getMessage();

        return PrismaClientKnownRequestError::create('P2010', sprintf('Raw query failed. Code: `%s`. Message: `%s`', $code, $message), ['code' => $code, 'message' => $message], $error);
    }

    /** Connection failure → PrismaClientInitializationError (P1000 auth, P1001 unreachable, P1003 unknown database). */
    public static function initialization(\PDOException $e): PrismaClientInitializationError
    {
        $message = $e->getMessage();
        $code = (int) ($e->errorInfo[1] ?? 0);
        $prismaCode = match (true) {
            $code === 1045 || stripos($message, 'password authentication failed') !== false || stripos($message, 'Access denied') !== false => 'P1000',
            $code === 1049 || stripos($message, 'does not exist') !== false || stripos($message, 'Unknown database') !== false => 'P1003',
            default => 'P1001',
        };
        $text = match ($prismaCode) {
            'P1000' => 'Authentication failed against the database server, the provided database credentials are not valid.',
            'P1003' => 'Database does not exist on the database server.',
            default => "Can't reach database server. Please make sure your database server is running.",
        };

        return new PrismaClientInitializationError($text . ' (' . $message . ')', $prismaCode, $e);
    }

    /** @return list<string> */
    private static function uniqueFields(string $message, DataModel $dataModel, ?string $model): array
    {
        // SQLite: "UNIQUE constraint failed: User.email, User.name"
        if (preg_match('/UNIQUE constraint failed: (.+)$/i', $message, $m) === 1) {
            $fields = [];
            foreach (explode(',', $m[1]) as $part) {
                $part = trim($part);
                $column = str_contains($part, '.') ? substr($part, strrpos($part, '.') + 1) : $part;
                $fields[] = self::fieldOfColumn($dataModel, $model, $column) ?? $column;
            }

            return $fields;
        }
        // PostgreSQL: unique constraint "User_email_key"; MySQL: for key 'User.User_email_key'
        $name = null;
        if (preg_match('/unique constraint "([^"]+)"/i', $message, $m) === 1) {
            $name = $m[1];
        } elseif (preg_match("/for key '([^']+)'/i", $message, $m) === 1) {
            $name = str_contains($m[1], '.') ? substr($m[1], strrpos($m[1], '.') + 1) : $m[1];
        }
        if ($name !== null) {
            $constraint = $dataModel->constraint($name);
            if ($constraint !== null) {
                return $constraint[1];
            }
            if (($name === 'PRIMARY' || str_ends_with($name, '_pkey')) && $model !== null && $dataModel->has($model)) {
                return $dataModel->model($model)->primaryKey;
            }
        }
        // PostgreSQL detail: Key (email)=(a@b.c) already exists.
        if (preg_match('/Key \(([^)]+)\)=/', $message, $m) === 1) {
            return array_map(fn ($c) => self::fieldOfColumn($dataModel, $model, trim($c, ' "')) ?? trim($c, ' "'), explode(',', $m[1]));
        }

        return $name !== null ? [$name] : [];
    }

    /**
     * The name of the violated foreign key. PostgreSQL and MySQL / MariaDB name it in the message; SQLite only says
     * "FOREIGN KEY constraint failed", so the candidates come from the schema: the operation's model's foreign
     * keys (delete: the ones pointing at it), narrowed down to those whose fields the arguments write.
     *
     * @param array<string, mixed> $args
     */
    private static function foreignKeyConstraint(string $message, DataModel $dataModel, ?string $model, string $operation, array $args): ?string
    {
        if (preg_match('/foreign key constraint "([^"]+)"/i', $message, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/CONSTRAINT `((?:[^`]|``)+)` FOREIGN KEY/i', $message, $m) === 1) {
            return str_replace('``', '`', $m[1]);
        }
        if ($model === null) {
            return null;
        }
        $deleting = in_array($operation, ['delete', 'deleteMany'], true);
        $candidates = $dataModel->foreignKeys($model, $deleting);
        if (count($candidates) > 1 && ! $deleting) {
            $written = self::writtenKeys($args);
            $candidates = array_values(array_filter($candidates, fn (array $fk) => isset($written[$fk['relation']]) || array_intersect($fk['fields'], array_keys($written)) !== []));
        }

        return count($candidates) === 1 ? $candidates[0]['name'] : null;
    }

    /** @param array<string, mixed> $args @return array<string, true> top-level keys of the written data */
    private static function writtenKeys(array $args): array
    {
        $data = [];
        foreach (['data', 'create', 'update'] as $key) {
            $value = $args[$key] ?? null;
            if (! is_array($value)) {
                continue;
            }
            foreach (array_is_list($value) ? $value : [$value] as $row) {
                if (is_array($row)) {
                    $data += array_fill_keys(array_map('strval', array_keys($row)), true);
                }
            }
        }

        return $data;
    }

    private static function notNullColumn(string $message): ?string
    {
        return match (true) {
            preg_match('/null value in column "([^"]+)"/i', $message, $m) === 1 => $m[1],
            preg_match("/Column '([^']+)' cannot be null/i", $message, $m) === 1 => $m[1],
            preg_match("/Field '([^']+)' doesn't have a default value/i", $message, $m) === 1 => $m[1],
            preg_match('/NOT NULL constraint failed: [^.]+\.(\S+)/i', $message, $m) === 1 => $m[1],
            default => null,
        };
    }

    private static function fieldOfColumn(DataModel $dataModel, ?string $model, ?string $column): ?string
    {
        if ($column === null || $model === null || ! $dataModel->has($model)) {
            return $column;
        }
        foreach ($dataModel->model($model)->scalars as $name => $field) {
            if ($field->columnName() === $column) {
                return $name;
            }
        }
        // a nested write may have failed on another model
        foreach ($dataModel->models() as $info) {
            foreach ($info->scalars as $name => $field) {
                if ($field->columnName() === $column) {
                    return $name;
                }
            }
        }

        return $column;
    }
}
