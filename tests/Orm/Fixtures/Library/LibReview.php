<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Library;

use DateTimeImmutable;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\DeleteDateColumn;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** Reviews removed from a book's collection are soft-deleted. */
#[Entity(name: 'lib_reviews')]
final class LibReview
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $body;

    #[DeleteDateColumn]
    public ?DateTimeImmutable $deletedAt = null;

    #[ManyToOne(LibBook::class, inverseSide: 'reviews', onDelete: 'CASCADE', orphanedRowAction: 'soft-delete')]
    public ?LibBook $book = null;
}
