<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers\Fixtures\Schema;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;
use Nesto\Orm\Attributes\Unique;

#[Entity(name: 'sd_tags')]
final class SdTag
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Unique]
    #[Column(length: 40)]
    public string $label;
}
