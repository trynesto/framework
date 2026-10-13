<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\WriteSafety;

use DateTimeImmutable;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\CreateDateColumn;
use Nesto\Orm\Attributes\DeleteDateColumn;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** Soft-deletable book with class defaults, a nullable column (NULLS FIRST/LAST) and a stored generated column. */
#[Entity(name: 'ws_books')]
final class WsBook
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(unique: true)]
    public string $title = 'untitled';

    #[Column]
    public int $rating = 0;

    #[Column(nullable: true)]
    public ?string $note = null;

    #[Column(type: 'int', generatedType: 'STORED', asExpression: 'rating * 2')]
    public int $doubled;

    #[ManyToOne(WsShelf::class, inverseSide: 'books', onDelete: 'SET NULL')]
    public ?WsShelf $shelf = null;

    #[CreateDateColumn]
    public ?DateTimeImmutable $createdAt = null;

    #[DeleteDateColumn]
    public ?DateTimeImmutable $deletedAt = null;
}
