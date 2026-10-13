<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Lazy;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

#[Entity(name: 'lz_books')]
final class LzBook
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $title;

    #[ManyToOne(LzAuthor::class, inverseSide: 'books', lazy: true)]
    public ?LzAuthor $author = null;
}
