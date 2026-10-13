<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\DataAccess;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\OneToMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** Replication / query cache / streaming / view fixtures (tables da_*). */
#[Entity(name: 'da_authors')]
class DaAuthor
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(length: 80)]
    public string $name;

    /** @var list<DaNote> */
    #[OneToMany(DaNote::class, inverseSide: 'author')]
    public array $notes;
}
