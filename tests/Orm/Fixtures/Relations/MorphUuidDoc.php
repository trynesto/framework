<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Relations;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\MorphAlias;
use Nesto\Orm\Attributes\MorphMany;
use Nesto\Orm\Attributes\MorphToMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** uuid key; MorphToMany through a custom pivot shared with MorphIntDoc. */
#[Entity(name: 'morph_uuid_docs'), MorphAlias('uuid-doc')]
final class MorphUuidDoc
{
    #[PrimaryGeneratedColumn(type: 'uuid')]
    public string $id;

    #[Column]
    public string $title;

    #[MorphMany(MorphNote::class, 'notable')]
    public array $notes;

    #[MorphToMany(MorphIntDoc::class, 'owner', table: 'morph_uuid_docs_tags')]
    public array $tags;
}
