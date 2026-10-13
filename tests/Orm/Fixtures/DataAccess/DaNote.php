<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\DataAccess;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\JoinColumn;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

#[Entity(name: 'da_notes')]
class DaNote
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(length: 120)]
    public string $title;

    #[Column(type: 'text', nullable: true)]
    public ?string $body = null;

    #[Column]
    public int $views = 0;

    #[ManyToOne(DaAuthor::class, inverseSide: 'notes', nullable: true, onDelete: 'CASCADE')]
    #[JoinColumn(name: 'author_id')]
    public ?DaAuthor $author = null;
}
