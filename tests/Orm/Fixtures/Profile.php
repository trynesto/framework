<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\JoinColumn;
use Nesto\Orm\Attributes\OneToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

#[Entity]
final class Profile
{
    #[PrimaryGeneratedColumn(type: 'uuid')]
    public string $id;

    #[Column(type: 'text', nullable: true)]
    public ?string $bio = null;

    #[OneToOne(User::class, inverseSide: 'profile', onDelete: 'CASCADE')]
    #[JoinColumn(name: 'user_id')]
    public User $user;
}
