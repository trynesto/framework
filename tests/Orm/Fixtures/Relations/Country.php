<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Relations;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\HasManyThrough;
use Nesto\Orm\Attributes\OneToMany;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;

/** Country → writers → articles (HasManyThrough). */
#[Entity]
final class Country
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column]
    public string $name;

    /** @var list<Writer> */
    #[OneToMany(Writer::class, inverseSide: 'country', cascade: ['insert'])]
    public array $writers;

    /** @var list<Article> */
    #[HasManyThrough(Article::class, through: Writer::class)]
    public array $articles;

    /** Same relation with every key spelled out (property and column names both work). */
    #[HasManyThrough(Article::class, through: Writer::class, firstKey: 'country', secondKey: 'writer_id', localKey: 'id', secondLocalKey: 'id')]
    public array $articlesExplicit;
}
