<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Relations;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\MorphedByMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** Pivot "labelables": label_id, labelable_type, labelable_id. */
#[Entity]
final class Label
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(unique: true)]
    public string $name;

    /** @var list<Article> */
    #[MorphedByMany(Article::class, 'labelable')]
    public array $articles;

    /** @var list<Video> */
    #[MorphedByMany(Video::class, 'labelable')]
    public array $videos;
}
