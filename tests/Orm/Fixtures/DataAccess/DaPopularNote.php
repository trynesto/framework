<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\DataAccess;

use Nesto\Orm\Attributes\ViewColumn;
use Nesto\Orm\Attributes\ViewEntity;

/** A view from plain SQL; no `id` column: rows are told apart by all their columns. */
#[ViewEntity(name: 'da_popular_notes', expression: 'SELECT title, views FROM da_notes WHERE views >= 10')]
class DaPopularNote
{
    #[ViewColumn]
    public string $title;

    #[ViewColumn]
    public int $views;
}
