<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Library;

use DateTimeImmutable;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\DeleteDateColumn;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\JoinTable;
use Nesto\Orm\Attributes\ManyToMany;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\OneToMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;
use Nesto\Orm\Attributes\RelationId;

/**
 * Book of the cascade / orphan / relation-query fixtures:
 *   author    ManyToOne, cascade insert + update, orphans nullified (default)
 *   genres    owning ManyToMany, cascade insert
 *   chapters  orphans deleted · reviews: orphans soft-deleted · notes: orphans left alone
 */
#[Entity(name: 'lib_books')]
final class LibBook
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $title;

    #[Column]
    public int $pages = 0;

    #[DeleteDateColumn]
    public ?DateTimeImmutable $deletedAt = null;

    #[ManyToOne(LibAuthor::class, inverseSide: 'books', onDelete: 'SET NULL', cascade: ['insert', 'update'])]
    public ?LibAuthor $author = null;

    /** @var list<LibGenre> */
    #[ManyToMany(LibGenre::class, inverseSide: 'books', cascade: ['insert'])]
    #[JoinTable(name: 'lib_book_genres', joinColumn: 'book_id', inverseJoinColumn: 'genre_id')]
    public array $genres;

    /** @var list<LibChapter> */
    #[OneToMany(LibChapter::class, inverseSide: 'book', cascade: true)]
    public array $chapters;

    /** @var list<LibReview> */
    #[OneToMany(LibReview::class, inverseSide: 'book', cascade: true)]
    public array $reviews;

    /** @var list<LibNote> */
    #[OneToMany(LibNote::class, inverseSide: 'book', cascade: true)]
    public array $notes;

    #[RelationId('author')]
    public ?int $authorId = null;

    /** @var list<int> */
    #[RelationId('genres')]
    public array $genreIds = [];
}
