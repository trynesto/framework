<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Lazy;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\OneToMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** Lazy relations need PHP 8.4+: building this metadata on 8.3 throws. */
#[Entity(name: 'lz_authors')]
final class LzAuthor
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    /** @var iterable<LzBook> */
    #[OneToMany(LzBook::class, inverseSide: 'author', lazy: true, cascade: ['insert'])]
    public iterable $books;
}
