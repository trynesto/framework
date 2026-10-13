<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Relations;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\HasOneThrough;
use Nesto\Orm\Attributes\OneToMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** Laravel's hasOneThrough example: mechanic → car → owner. */
#[Entity]
final class Mechanic
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    /** @var list<Car> */
    #[OneToMany(Car::class, inverseSide: 'mechanic')]
    public array $cars;

    #[HasOneThrough(Owner::class, through: Car::class)]
    public ?Owner $carOwner = null;
}
