<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Maps a class to a database view (TypeORM's @ViewEntity): read through repositories / query builders like
 * an entity, never written. Its properties are #[ViewColumn]s.
 *
 *   #[ViewEntity(expression: 'SELECT p.id AS id, p.title AS title, u.name AS author FROM posts p JOIN users u ON u.id = p.user_id')]
 *   final class PostSummary { #[ViewColumn] public int $id; #[ViewColumn] public string $title; #[ViewColumn] public string $author; }
 *
 *   // PHP attributes cannot hold closures (before 8.5): name a static method building the query instead
 *   #[ViewEntity(name: 'author_stats', expression: [self::class, 'query'], dependsOn: [PostSummary::class])]
 *   final class AuthorStats {
 *       public static function query(DataSource $ds): QueryBuilder|string {
 *           return $ds->createQueryBuilder(User::class, 'u')->select(['u.id AS id', 'COUNT(p.id) AS posts'])
 *               ->leftJoin('u.posts', 'p')->groupBy('u.id');
 *       }
 *       ...
 *   }
 *
 *   #[ViewEntity(expression: '...', materialized: true)]   // PostgreSQL MATERIALIZED VIEW (a plain view elsewhere)
 *
 * Schema sync / migration:generate create, drop and recreate views when their expression changes (compared
 * with the expression recorded in the `metadataTableName` table, "typeorm_metadata", like TypeORM).
 * `dependsOn` lists the views (classes or names) this one selects from: they are created first and a
 * change to them recreates this view too.
 *
 * @param string|array{0: class-string, 1: string}|null $expression SQL, or [Class::class, 'staticMethod'] receiving the DataSource
 * @param list<class-string|string> $dependsOn
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ViewEntity
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly string|array|\Closure|null $expression = null,
        public readonly ?string $database = null,
        public readonly ?string $schema = null,
        public readonly bool $synchronize = true,
        public readonly bool $materialized = false,
        public readonly array $dependsOn = [],
    ) {}
}
