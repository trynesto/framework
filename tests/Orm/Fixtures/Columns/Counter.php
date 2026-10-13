<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Columns;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** A VIRTUAL generated column (MySQL, MariaDB, SQLite; PostgreSQL 18+). */
#[Entity(name: 'col_counters')]
final class Counter
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(type: 'int')]
    public int $hits = 0;

    #[Column(type: 'int', generatedType: 'VIRTUAL', asExpression: 'hits + 1')]
    public ?int $next = null;
}
