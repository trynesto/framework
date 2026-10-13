<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Relations;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

#[Entity]
final class Car
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $model;

    #[ManyToOne(Mechanic::class, inverseSide: 'cars', onDelete: 'CASCADE')]
    public Mechanic $mechanic;
}
