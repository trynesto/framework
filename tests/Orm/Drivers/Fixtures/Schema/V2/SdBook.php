<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers\Fixtures\Schema\V2;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\JoinColumn;
use Nesto\Orm\Attributes\JoinTable;
use Nesto\Orm\Attributes\ManyToMany;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/**
 * Version 2 of `sd_books`: title longer and nullable, author FK now RESTRICT, editor FK removed
 * (createForeignKeyConstraints: false), reviewer FK added, a publisher relation to a new table,
 * the join table's second foreign key named.
 */
#[Entity(name: 'sd_books')]
final class SdBook
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(length: 250, nullable: true)]
    public ?string $title = null;

    #[ManyToOne(SdAuthor::class, nullable: false, onDelete: 'RESTRICT')]
    #[JoinColumn(name: 'author_id', foreignKeyConstraintName: 'fk_sd_books_author')]
    public SdAuthor $author;

    #[ManyToOne(SdAuthor::class, createForeignKeyConstraints: false)]
    public ?SdAuthor $editor = null;

    #[ManyToOne(SdAuthor::class, onDelete: 'SET NULL')]
    public ?SdAuthor $reviewer = null;

    #[ManyToOne(SdPublisher::class)]
    public ?SdPublisher $publisher = null;

    /** @var list<SdTag> */
    #[ManyToMany(SdTag::class)]
    #[JoinTable(
        name: 'sd_book_tags',
        joinColumn: ['name' => 'book_id', 'foreignKeyConstraintName' => 'fk_sd_book_tags_book'],
        inverseJoinColumn: ['name' => 'tag_id', 'foreignKeyConstraintName' => 'fk_sd_book_tags_tag'],
    )]
    public array $tags = [];
}
