<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Inheritance;

use Nesto\Orm\Attributes\ChildEntity;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\JoinColumn;
use Nesto\Orm\Attributes\ManyToOne;

#[ChildEntity('photo')]
class StiPhoto extends StiContent
{
    #[Column]
    public int $size;

    #[Column(nullable: true)]
    public ?string $lens = null;

    /** A relation only photos have: its foreign key is a nullable column of the shared table. */
    #[ManyToOne(StiAuthor::class)]
    #[JoinColumn(name: 'photographer_id')]
    public ?StiAuthor $photographer = null;
}
