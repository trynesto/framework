<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Library;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** Notes removed from a book's collection are left alone. */
#[Entity(name: 'lib_notes')]
final class LibNote
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $text;

    #[ManyToOne(LibBook::class, inverseSide: 'notes', onDelete: 'CASCADE', orphanedRowAction: 'disable')]
    public ?LibBook $book = null;
}
