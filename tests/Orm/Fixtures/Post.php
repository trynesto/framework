<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures;

use DateTimeImmutable;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\CreateDateColumn;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\JoinColumn;
use Nesto\Orm\Attributes\JoinTable;
use Nesto\Orm\Attributes\ManyToMany;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

#[Entity(name: 'posts')]
final class Post
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $title;

    #[Column(type: 'text', nullable: true)]
    public ?string $body = null;

    #[Column]
    public PostStatus $status = PostStatus::Draft;

    #[Column]
    public int $views = 0;

    #[Column(type: 'decimal', precision: 8, scale: 2)]
    public float $price = 0.0;

    #[ManyToOne(User::class, inverseSide: 'posts', onDelete: 'CASCADE', cascade: ['insert'])]
    #[JoinColumn(name: 'user_id')]
    public User $user;

    /** @var list<Tag> */
    #[ManyToMany(Tag::class, inverseSide: 'posts', cascade: ['insert'])]
    #[JoinTable(name: 'post_tags', joinColumn: 'post_id', inverseJoinColumn: 'tag_id')]
    public array $tags;

    #[CreateDateColumn]
    public ?DateTimeImmutable $createdAt = null;
}
