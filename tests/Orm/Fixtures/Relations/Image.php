<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Relations;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\MorphTo;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** MorphTo with custom column names: imageable_kind + imageable_ref. */
#[Entity]
final class Image
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $url;

    #[MorphTo(type: 'imageable_kind', id: 'imageable_ref')]
    public ?object $imageable = null;
}
