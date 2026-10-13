<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\DataAccess;

use Nesto\Orm\Attributes\ViewColumn;
use Nesto\Orm\Attributes\ViewEntity;
use Nesto\Orm\DataSource;
use Nesto\Orm\QueryBuilder;

/** A view built by a QueryBuilder (portable quoting on every driver). */
#[ViewEntity(name: 'da_author_stats', expression: [self::class, 'query'])]
class DaAuthorStats
{
    #[ViewColumn]
    public int $id;

    #[ViewColumn]
    public string $name;

    #[ViewColumn(name: 'note_count')]
    public int $notes;

    #[ViewColumn]
    public int $totalViews;

    public static function query(DataSource $ds): QueryBuilder
    {
        return $ds->createQueryBuilder(DaAuthor::class, 'a')
            ->select(['a.id AS id', 'a.name AS name', 'COUNT(n.id) AS note_count', 'COALESCE(SUM(n.views), 0) AS total_views'])
            ->leftJoin('a.notes', 'n')
            ->groupBy('a.id', 'a.name');
    }
}
