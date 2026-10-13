<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Library;

use DateTimeImmutable;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\DeleteDateColumn;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\JoinColumn;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\OneToMany;
use Nesto\Orm\Attributes\OneToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;
use Nesto\Orm\Attributes\RelationId;

/** Cascades everything to its books and bio; soft-deletable. */
#[Entity(name: 'lib_authors')]
final class LibAuthor
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    #[Column]
    public int $rank = 0;

    #[DeleteDateColumn]
    public ?DateTimeImmutable $deletedAt = null;

    /** @var list<LibBook> */
    #[OneToMany(LibBook::class, inverseSide: 'author', cascade: true)]
    public array $books;

    #[OneToOne(LibBio::class, inverseSide: 'author', cascade: true)]
    public ?LibBio $bio = null;

    /** References a book that references this author back (lib_authors ↔ lib_books FK cycle). */
    #[ManyToOne(LibBook::class, onDelete: 'SET NULL', cascade: ['insert'])]
    #[JoinColumn(name: 'favorite_book_id')]
    public ?LibBook $favoriteBook = null;

    /** @var list<int> */
    #[RelationId('books')]
    public array $bookIds = [];

    // plain properties filled by QueryBuilder::*JoinAndMap*() / loadRelation*AndMap()
    public ?LibBook $longestBook = null;
    /** @var list<LibBook> */
    public array $longBooks = [];
    public int $bookCount = 0;
    /** @var list<int> */
    public array $bookIdList = [];
}
