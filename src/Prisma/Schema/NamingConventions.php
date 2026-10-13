<?php

declare(strict_types=1);

namespace Nesto\Prisma\Schema;

/**
 * Prisma's default database names for constraints and indexes (psl `ConstraintNames`):
 *
 *   primary key       {Table}_pkey                     "Post_pkey"
 *   unique index      {Table}_{column}_{column}_key    "User_email_key"
 *   index             {Table}_{columns}_idx            "Post_title_createdAt_idx"
 *   foreign key       {Table}_{columns}_fkey           "Post_authorId_fkey"
 *   relation name     {ModelA}To{ModelB} (sorted)      "PostToUser"
 *   m2m join table    _{RelationName}, columns A / B   "_CategoryToPost"
 *
 * Names are truncated so they fit the database's identifier limit (PostgreSQL 63, MySQL 64), keeping the suffix.
 */
final class NamingConventions
{
    public static function maxIdentifierLength(string $provider): int
    {
        return match ($provider) {
            'postgresql' => 63,
            'mysql' => 64,
            default => 10000,
        };
    }

    public static function primaryKeyName(string $table, string $provider): string
    {
        $limit = self::maxIdentifierLength($provider);
        $trimmed = strlen($table) >= $limit - 5 ? substr($table, 0, $limit - 5) : $table;

        return $trimmed . '_pkey';
    }

    /** @param list<string> $columns */
    public static function uniqueIndexName(string $table, array $columns, string $provider): string
    {
        return self::indexName($table, $columns, '_key', $provider);
    }

    /** @param list<string> $columns */
    public static function normalIndexName(string $table, array $columns, string $provider): string
    {
        return self::indexName($table, $columns, '_idx', $provider);
    }

    /** @param list<string> $columns */
    public static function foreignKeyName(string $table, array $columns, string $provider): string
    {
        return self::indexName($table, $columns, '_fkey', $provider);
    }

    /** @param list<string> $columns */
    private static function indexName(string $table, array $columns, string $suffix, string $provider): string
    {
        $limit = self::maxIdentifierLength($provider);
        $name = $table . '_' . implode('_', $columns);
        if (strlen($name) >= $limit - strlen($suffix)) {
            $name = substr($name, 0, $limit - strlen($suffix));
        }

        return $name . $suffix;
    }

    /** Default relation name: both model names, sorted, joined by "To" ("PostToUser", "UserToUser"). */
    public static function relationName(string $modelA, string $modelB): string
    {
        $names = [$modelA, $modelB];
        sort($names, SORT_STRING);

        return $names[0] . 'To' . $names[1];
    }

    /** Join table of an implicit many-to-many relation. */
    public static function joinTableName(string $relationName): string
    {
        return '_' . $relationName;
    }
}
