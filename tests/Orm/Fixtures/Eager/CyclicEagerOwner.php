<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Eager;

use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\OneToMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

#[Entity(name: 'eg_cyclic_owners')]
final class CyclicEagerOwner
{
    #[PrimaryGeneratedColumn]
    public int $id;

    /** @var list<CyclicEager> */
    #[OneToMany(CyclicEager::class, inverseSide: 'owner', eager: true)]
    public array $items;
}
