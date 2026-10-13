<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Relations;

use Nesto\Orm\Attributes\BelongsToThrough;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\JoinColumn;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** Custom FK name (vehicle_id): through relations pick it up from the ManyToOne. */
#[Entity]
final class Owner
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    #[ManyToOne(Car::class, onDelete: 'CASCADE', cascade: ['insert'])]
    #[JoinColumn(name: 'vehicle_id')]
    public Car $car;

    #[BelongsToThrough(Mechanic::class, through: Car::class)]
    public ?Mechanic $mechanic = null;
}
