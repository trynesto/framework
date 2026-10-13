<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Library;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\ManyToMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** Inverse side of LibBook::$genres (no cascade): saving it still writes the join table. */
#[Entity(name: 'lib_genres')]
final class LibGenre
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    /** @var list<LibBook> */
    #[ManyToMany(LibBook::class, inverseSide: 'genres')]
    public array $books;

    /** Filled by QueryBuilder::loadRelationCountAndMap(). */
    public int $bookCount = 0;
}
