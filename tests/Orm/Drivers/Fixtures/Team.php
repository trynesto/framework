<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers\Fixtures;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\JoinColumn;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\OneToMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** Team ↔ Member reference each other: synchronize() must create both tables despite the FK cycle. */
#[Entity]
final class Team
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    #[ManyToOne(Member::class, onDelete: 'SET NULL')]
    #[JoinColumn(name: 'captain_id')]
    public ?Member $captain = null;

    /** @var list<Member> */
    #[OneToMany(Member::class, inverseSide: 'team', cascade: ['insert'])]
    public array $members;
}
