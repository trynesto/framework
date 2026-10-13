<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures;

use DateTimeImmutable;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\CreateDateColumn;
use Nesto\Orm\Attributes\DeleteDateColumn;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\Index;
use Nesto\Orm\Attributes\OneToMany;
use Nesto\Orm\Attributes\OneToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;
use Nesto\Orm\Attributes\UpdateDateColumn;

#[Entity]
#[Index(columns: ['name'])]
final class User
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    #[Column(unique: true)]
    public string $email;

    #[Column(default: true)]
    public bool $active = true;

    #[Column]
    public int $age = 0;

    #[Column(type: 'json', nullable: true)]
    public ?array $settings = null;

    #[CreateDateColumn]
    public ?DateTimeImmutable $createdAt = null;

    #[UpdateDateColumn]
    public ?DateTimeImmutable $updatedAt = null;

    #[DeleteDateColumn]
    public ?DateTimeImmutable $deletedAt = null;

    /** @var list<Post> */
    #[OneToMany(Post::class, inverseSide: 'user', cascade: ['insert'])]
    public array $posts;

    #[OneToOne(Profile::class, inverseSide: 'user', cascade: ['insert'])]
    public ?Profile $profile = null;
}
