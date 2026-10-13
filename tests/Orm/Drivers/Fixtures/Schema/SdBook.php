<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers\Fixtures\Schema;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\JoinColumn;
use Nesto\Orm\Attributes\JoinTable;
use Nesto\Orm\Attributes\ManyToMany;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

#[Entity(name: 'sd_books')]
final class SdBook
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(length: 200)]
    public string $title;

    #[ManyToOne(SdAuthor::class, nullable: false, onDelete: 'CASCADE', onUpdate: 'CASCADE')]
    #[JoinColumn(name: 'author_id', foreignKeyConstraintName: 'fk_sd_books_author')]
    public SdAuthor $author;

    #[ManyToOne(SdAuthor::class, onDelete: 'SET NULL')]
    public ?SdAuthor $editor = null;

    #[ManyToOne(SdAuthor::class, createForeignKeyConstraints: false)]
    public ?SdAuthor $reviewer = null;

    /** @var list<SdTag> */
    #[ManyToMany(SdTag::class)]
    #[JoinTable(name: 'sd_book_tags', joinColumn: ['name' => 'book_id', 'foreignKeyConstraintName' => 'fk_sd_book_tags_book'], inverseJoinColumn: 'tag_id')]
    public array $tags = [];
}
