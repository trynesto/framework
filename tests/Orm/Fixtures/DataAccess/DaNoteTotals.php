<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\DataAccess;

use Nesto\Orm\Attributes\ViewColumn;
use Nesto\Orm\Attributes\ViewEntity;

/** A materialized view on PostgreSQL (a plain view elsewhere). */
#[ViewEntity(name: 'da_note_totals', expression: 'SELECT COUNT(*) AS notes, COALESCE(SUM(views), 0) AS views FROM da_notes', materialized: true)]
class DaNoteTotals
{
    #[ViewColumn]
    public int $notes;

    #[ViewColumn]
    public int $views;
}
