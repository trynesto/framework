<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Relations;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\MorphTo;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** MorphTo without declared types: synchronize sizes notable_id for int AND uuid parents (varchar). */
#[Entity(name: 'morph_notes')]
final class MorphNote
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $text;

    #[MorphTo]
    public ?object $notable = null;
}
