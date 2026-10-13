<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Relations;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\MorphMany;
use Nesto\Orm\Attributes\MorphedByMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** int key. */
#[Entity(name: 'morph_int_docs')]
final class MorphIntDoc
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $title;

    #[MorphMany(MorphNote::class, 'notable')]
    public array $notes;

    #[MorphedByMany(MorphUuidDoc::class, 'owner', table: 'morph_uuid_docs_tags')]
    public array $owners;
}
