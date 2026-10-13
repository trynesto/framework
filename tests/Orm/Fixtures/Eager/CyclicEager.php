<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Eager;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** CyclicEager::$owner (eager) ↔ CyclicEagerOwner::$items (eager): rejected like TypeORM. */
#[Entity(name: 'eg_cyclic')]
final class CyclicEager
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    #[ManyToOne(CyclicEagerOwner::class, inverseSide: 'items', eager: true)]
    public ?CyclicEagerOwner $owner = null;
}
