<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Columns;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\Generated;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** PostgreSQL-only column features: identity key, array columns, a generated non-key sequence. */
#[Entity(name: 'col_pg_features')]
final class PgFeature
{
    #[PrimaryGeneratedColumn('identity', type: 'int')]
    public int $id;

    #[Column(type: 'text', array: true)]
    public array $tags = [];

    #[Column(type: 'int', array: true, nullable: true)]
    public ?array $scores = null;

    #[Column(type: 'int')]
    #[Generated('increment')]
    public int $ticket;

    #[Column(type: 'jsonb', nullable: true)]
    public ?array $doc = null;
}
