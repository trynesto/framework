<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Relations;

use Nesto\Orm\Attributes\BelongsToThrough;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\MorphAlias;
use Nesto\Orm\Attributes\MorphMany;
use Nesto\Orm\Attributes\MorphOne;
use Nesto\Orm\Attributes\MorphToMany;
use Nesto\Orm\Attributes\OneToMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

#[Entity, MorphAlias('article')]
final class Article
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $title;

    #[ManyToOne(Writer::class, inverseSide: 'articles', onDelete: 'CASCADE')]
    public Writer $writer;

    /** @var list<Comment> */
    #[MorphMany(Comment::class, 'commentable')]
    public array $comments;

    #[MorphOne(Image::class, 'imageable')]
    public ?Image $cover = null;

    /** @var list<Label> */
    #[MorphToMany(Label::class, 'labelable')]
    public array $labels;

    #[BelongsToThrough(Country::class, through: Writer::class)]
    public ?Country $country = null;

    /** @var list<Paragraph> */
    #[OneToMany(Paragraph::class, inverseSide: 'article')]
    public array $paragraphs;
}
