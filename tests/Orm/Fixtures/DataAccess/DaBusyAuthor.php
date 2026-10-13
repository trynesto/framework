<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\DataAccess;

use Nesto\Orm\Attributes\ViewColumn;
use Nesto\Orm\Attributes\ViewEntity;

/** A view selecting from another view (dependsOn). */
#[ViewEntity(name: 'da_busy_authors', expression: 'SELECT id, name FROM da_author_stats WHERE note_count > 1', dependsOn: [DaAuthorStats::class])]
class DaBusyAuthor
{
    #[ViewColumn]
    public int $id;

    #[ViewColumn]
    public string $name;
}
