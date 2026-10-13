<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Eager;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\OneToMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

#[Entity(name: 'eg_countries')]
final class EgCountry
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    /** @var list<EgCity> not eager: City::$country is, and both directions would be a cycle */
    #[OneToMany(EgCity::class, inverseSide: 'country')]
    public array $cities;
}
