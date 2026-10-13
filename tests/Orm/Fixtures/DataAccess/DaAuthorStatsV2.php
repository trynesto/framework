<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\DataAccess;

use Nesto\Orm\Attributes\ViewColumn;
use Nesto\Orm\Attributes\ViewEntity;
use Nesto\Orm\DataSource;
use Nesto\Orm\QueryBuilder;

/** da_author_stats with a changed expression (only notes with views count) and a parameter inlined. */
#[ViewEntity(name: 'da_author_stats', expression: [self::class, 'query'])]
class DaAuthorStatsV2
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
            ->leftJoin('a.notes', 'n', 'n.views > :min', ['min' => 0])
            ->groupBy('a.id', 'a.name');
    }
}
