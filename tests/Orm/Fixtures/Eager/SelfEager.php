<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Eager;

use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** A self-referencing eager relation would load parents forever: rejected. */
#[Entity(name: 'eg_self')]
final class SelfEager
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[ManyToOne(SelfEager::class, eager: true)]
    public ?SelfEager $parent = null;
}
