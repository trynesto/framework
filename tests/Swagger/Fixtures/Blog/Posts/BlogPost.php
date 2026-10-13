<?php

declare(strict_types=1);

namespace Nesto\Tests\Swagger\Fixtures\Blog\Posts;

use Nesto\Swagger\Attributes\ApiHideProperty;
use Nesto\Swagger\Attributes\ApiProperty;
use Nesto\Tests\Swagger\Fixtures\Blog\{Tags\Tag, Users\Author as Writer};
use Nesto\Transformer\Attributes\Exclude;
use Nesto\Transformer\Attributes\Expose;

final class BlogPost
{
    public int $id;

    public Writer $author;

    /** @var list<Tag> imported short name */
    public array $tags;

    /** @var array<string, Tag> keyed by name */
    public array $tagsByName;

    /** @var Tag[] */
    public array $related = [];

    /** @var ?Writer */
    public $editor;

    /** @var Tag|null */
    public $mainTag;

    /** @var list<array{name: string, count?: int}> */
    public array $stats = [];

    /** @var list<Comment> same namespace */
    public array $comments = [];

    #[Exclude]
    public string $image;

    public function __construct(
        /** @var list<Tag> */
        public array $promoted = [],
    ) {}

    #[Expose(name: 'imageUrl')]
    #[ApiProperty(format: 'uri', description: 'Public URL of the image')]
    public function imageUrl(): string
    {
        return '/storage/' . $this->image;
    }

    #[Expose]
    public function getExcerpt(): ?string
    {
        return null;
    }

    #[Expose(groups: ['detail'])]
    public function wordCount(): int
    {
        return 0;
    }

    #[Expose]
    #[ApiHideProperty]
    public function hidden(): int
    {
        return 0;
    }

    public function notExposed(): int
    {
        return 0;
    }
}
