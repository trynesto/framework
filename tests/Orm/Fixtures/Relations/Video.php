<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Relations;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\MorphMany;
use Nesto\Orm\Attributes\MorphToMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** Aliased through the `morphMap` option ('video' => Video::class). */
#[Entity]
final class Video
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $title;

    /** @var list<Comment> */
    #[MorphMany(Comment::class, 'commentable')]
    public array $comments;

    /** @var list<Label> */
    #[MorphToMany(Label::class, 'labelable')]
    public array $labels;
}
