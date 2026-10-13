<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Library;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\JoinColumn;
use Nesto\Orm\Attributes\OneToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** Owning side of LibAuthor::$bio. */
#[Entity(name: 'lib_bios')]
final class LibBio
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $text;

    #[OneToOne(LibAuthor::class, inverseSide: 'bio', onDelete: 'SET NULL')]
    #[JoinColumn(name: 'author_id')]
    public ?LibAuthor $author = null;
}
