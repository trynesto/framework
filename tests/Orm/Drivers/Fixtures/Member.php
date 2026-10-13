<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers\Fixtures;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

#[Entity]
final class Member
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    #[ManyToOne(Team::class, inverseSide: 'members', onDelete: 'CASCADE')]
    public ?Team $team = null;
}
