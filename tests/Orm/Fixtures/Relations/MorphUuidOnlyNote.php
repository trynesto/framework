<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Relations;

use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\MorphTo;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** MorphTo(types: [...]): the id column takes the uuid key type. */
#[Entity(name: 'morph_uuid_only_notes')]
final class MorphUuidOnlyNote
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[MorphTo(name: 'notable', types: [MorphUuidDoc::class])]
    public ?MorphUuidDoc $doc = null;
}
