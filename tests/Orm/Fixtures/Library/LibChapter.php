<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Library;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** Chapters removed from a book's collection are deleted. */
#[Entity(name: 'lib_chapters')]
final class LibChapter
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $title;

    #[ManyToOne(LibBook::class, inverseSide: 'chapters', onDelete: 'CASCADE', orphanedRowAction: 'delete')]
    public ?LibBook $book = null;
}
