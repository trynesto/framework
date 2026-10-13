<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Inheritance;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\JoinColumn;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\OneToMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** Relations pointing at the inheritance root and at a child entity. */
#[Entity('sti_authors')]
class StiAuthor
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    /** @var list<StiContent> every type */
    #[OneToMany(StiContent::class, inverseSide: 'author')]
    public array $contents;

    /** @var list<StiVideo> only videos (and HD videos), through the same foreign key */
    #[OneToMany(StiVideo::class, inverseSide: 'author')]
    public array $videos;

    #[ManyToOne(StiPhoto::class, onDelete: 'SET NULL')]
    #[JoinColumn(name: 'cover_id')]
    public ?StiPhoto $cover = null;
}
