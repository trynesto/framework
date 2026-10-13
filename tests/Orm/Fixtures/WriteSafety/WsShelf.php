<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\WriteSafety;

use DateTimeImmutable;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\CreateDateColumn;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\OneToMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** A shelf whose books cascade insert AND update (children referenced by id must only get their key). */
#[Entity(name: 'ws_shelves')]
final class WsShelf
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    #[Column]
    public string $color = 'white';

    /** @var list<WsBook> */
    #[OneToMany(WsBook::class, inverseSide: 'shelf', cascade: ['insert', 'update'])]
    public array $books = [];

    #[CreateDateColumn]
    public ?DateTimeImmutable $createdAt = null;
}
