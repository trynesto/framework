<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Relations;

use DateTimeImmutable;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\DeleteDateColumn;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\ManyToOne;
use Nesto\Orm\Attributes\MorphOne;
use Nesto\Orm\Attributes\OneToMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** No morph alias: its images store the class name in imageable_kind. Soft-deletable intermediate. */
#[Entity]
final class Writer
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    #[ManyToOne(Country::class, inverseSide: 'writers', onDelete: 'CASCADE')]
    public ?Country $country = null;

    /** @var list<Article> */
    #[OneToMany(Article::class, inverseSide: 'writer')]
    public array $articles;

    #[MorphOne(Image::class, 'imageable')]
    public ?Image $avatar = null;

    #[DeleteDateColumn]
    public ?DateTimeImmutable $deletedAt = null;
}
