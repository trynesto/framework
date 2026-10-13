<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Eager;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** street → city (eager) → country (eager): find(EgStreet) loads all three. */
#[Entity(name: 'eg_streets')]
final class EgStreet
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    #[ManyToOne(EgCity::class, inverseSide: 'streets', eager: true, cascade: ['insert'])]
    public ?EgCity $city = null;
}
