<?php

declare(strict_types=1);

namespace Nesto\Tests\Transformer;

use Nesto\Orm\Pagination;
use Nesto\Transformer\Attributes\Exclude;
use Nesto\Transformer\Attributes\Expose;
use Nesto\Transformer\ClassTransformer;
use PHPUnit\Framework\TestCase;

final class ArticleView
{
    public int $id = 1;
    public string $body = 'one two three four five';
    #[Exclude]
    public string $secret = 'x';
    #[Expose(groups: ['admin'])]
    public string $authorEmail = 'a@b.c';

    #[Expose]
    public function getExcerpt(): string
    {
        return substr($this->body, 0, 7) . '…';
    }

    #[Expose(name: 'words')]
    public function wordCount(): int
    {
        return str_word_count($this->body);
    }

    #[Expose(groups: ['admin'])]
    public function getInternalScore(): int
    {
        return 42;
    }

    public function notExposed(): string
    {
        return 'nope';
    }
}

final class PhotoView
{
    public int $id = 7;
    public array $images = [['path' => 'a.jpg']];

    #[Expose] // Laravel accessor naming: "url"
    public function getUrlAttribute(): string
    {
        return '/photos/' . $this->id;
    }

    #[Expose] // same key as $images: decorates the stored value in the output
    public function getImages(): array
    {
        return array_map(fn (array $i) => $i + ['url' => '/storage/' . $i['path']], $this->images);
    }
}

final class ExposedMethodsTest extends TestCase
{
    public function test_laravel_style_accessors_and_overriding_a_property(): void
    {
        $plain = ClassTransformer::instanceToPlain(new PhotoView);

        self::assertSame(['id' => 7, 'images' => [['path' => 'a.jpg', 'url' => '/storage/a.jpg']], 'url' => '/photos/7'], $plain);
    }

    public function test_exposed_methods_are_serialized(): void
    {
        $plain = ClassTransformer::instanceToPlain(new ArticleView);

        self::assertSame(['id' => 1, 'body' => 'one two three four five', 'excerpt' => 'one two…', 'words' => 5], $plain);
    }

    public function test_exposed_methods_honour_groups_and_naming(): void
    {
        $plain = ClassTransformer::instanceToPlain(new ArticleView, ['groups' => ['admin'], 'namingStrategy' => 'snake']);

        self::assertSame('a@b.c', $plain['author_email']);
        self::assertSame(42, $plain['internal_score']);
        self::assertSame(5, $plain['words']);
    }

    public function test_exposed_methods_are_never_hydrated(): void
    {
        $view = ClassTransformer::plainToInstance(ArticleView::class, ['excerpt' => 'ignored', 'id' => 9]);

        self::assertSame(9, $view->id);
        self::assertSame(['excerpt'], ClassTransformer::unknownKeys(ArticleView::class, ['excerpt' => 'x']));
    }

    public function test_pagination_entities_get_the_same_serialization_options(): void
    {
        $page = new Pagination([new ArticleView], 1, 1, 10);

        $plain = ClassTransformer::toPlain($page, ['groups' => ['admin']]);

        self::assertSame(['total' => 1, 'page' => 1, 'perPage' => 10, 'lastPage' => 1], $plain['meta']);
        self::assertSame('a@b.c', $plain['data'][0]['authorEmail']);
        self::assertArrayNotHasKey('secret', $plain['data'][0]);
        self::assertArrayNotHasKey('authorEmail', ClassTransformer::toPlain($page)['data'][0]);
    }
}
