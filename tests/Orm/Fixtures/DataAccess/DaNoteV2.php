<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\DataAccess;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\JoinColumn;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** da_notes with a longer title: an ALTER of a column the views use. */
#[Entity(name: 'da_notes')]
class DaNoteV2
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(length: 200)]
    public string $title;

    #[Column(type: 'text', nullable: true)]
    public ?string $body = null;

    #[Column]
    public int $views = 0;

    #[ManyToOne(DaAuthor::class, nullable: true, onDelete: 'CASCADE')]
    #[JoinColumn(name: 'author_id')]
    public ?DaAuthor $author = null;
}
