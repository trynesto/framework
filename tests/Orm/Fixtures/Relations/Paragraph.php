<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Relations;

use Nesto\Orm\Attributes\BelongsToThrough;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** Paragraph → article → writer → country (BelongsToThrough with two intermediates). */
#[Entity]
final class Paragraph
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $text;

    #[ManyToOne(Article::class, inverseSide: 'paragraphs', onDelete: 'CASCADE')]
    public Article $article;

    /** Listed from the target side, like staudenmeir/belongs-to-through. */
    #[BelongsToThrough(Country::class, through: [Writer::class, Article::class])]
    public ?Country $country = null;

    /** Same chain listed from this side. */
    #[BelongsToThrough(Country::class, through: [Article::class, Writer::class])]
    public ?Country $countryNearFirst = null;

    #[BelongsToThrough(Writer::class, through: Article::class)]
    public ?Writer $writer = null;
}
