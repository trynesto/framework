<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Eager;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\OneToMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

#[Entity(name: 'eg_cities')]
final class EgCity
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    #[ManyToOne(EgCountry::class, inverseSide: 'cities', eager: true)]
    public ?EgCountry $country = null;

    /** @var list<EgStreet> */
    #[OneToMany(EgStreet::class, inverseSide: 'city', cascade: ['insert'])]
    public array $streets;
}
