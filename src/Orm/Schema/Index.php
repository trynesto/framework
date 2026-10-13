<?php

declare(strict_types=1);

namespace Nesto\Orm\Schema;

/**
 * An index or UNIQUE constraint of a table.
 *
 * `explicitName` is false for generated names ("idx_users_email"): such indexes match an existing index on
 * the same columns whatever it is called. `constraint` marks UNIQUE constraints (dropped with ALTER TABLE
 * ... DROP CONSTRAINT on PostgreSQL, by a table rebuild on SQLite). `implicit` marks indexes the database
 * manages itself or that cannot be expressed by entities (MySQL's foreign-key indexes, expression / fulltext
 * indexes): they satisfy a wanted index but are never dropped.
 *
 * Optional per-column options for front-ends that declare them (Prisma's `@@index([a(sort: Desc)], type: Hash)`):
 * `sorts` (column => ASC | DESC), `lengths` (column => prefix length, MySQL / MariaDB) and `using` (the index
 * method: BTREE, HASH, GIN, GIST, BRIN, SPGIST — PostgreSQL; HASH / BTREE — MySQL). They are written by
 * Grammar but not compared (introspection does not report them).
 */
final class Index
{
    /**
     * @param list<string>          $columns
     * @param array<string, string> $sorts
     * @param array<string, int>    $lengths
     */
    public function __construct(
        public readonly array $columns,
        public readonly bool $unique = false,
        public readonly ?string $name = null,
        public readonly bool $explicitName = false,
        public readonly ?string $where = null,
        public readonly bool $constraint = false,
        public readonly bool $implicit = false,
        public readonly array $sorts = [],
        public readonly array $lengths = [],
        public readonly ?string $using = null,
    ) {}

    /**
     * Default index name "idx_{table}_{columns}" / "uq_{table}_{columns}". Names longer than 63 characters
     * (PostgreSQL truncates at 63, MySQL rejects more than 64) are shortened with a hash suffix.
     *
     * @param list<string> $columns
     */
    public static function defaultName(string $table, array $columns, bool $unique): string
    {
        $name = ($unique ? 'uq_' : 'idx_') . $table . '_' . implode('_', $columns);
        if (strlen($name) <= 63) {
            return $name;
        }

        return substr($name, 0, 54) . '_' . substr(md5($name), 0, 8);
    }
}
