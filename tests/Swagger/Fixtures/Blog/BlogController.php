<?php

declare(strict_types=1);

namespace Nesto\Tests\Swagger\Fixtures\Blog;

use Nesto\Common\Attributes\Body;
use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Get;
use Nesto\Common\Attributes\Patch;
use Nesto\Common\Attributes\Post;
use Nesto\Orm\Pagination;
use Nesto\Swagger\Attributes\ApiOkResponse;
use Nesto\Tests\Swagger\Fixtures\Blog\Posts\BlogPost;
use Nesto\Tests\Swagger\Fixtures\Blog\Posts\CreatePostInput;
use Nesto\Tests\Swagger\Fixtures\Blog\Posts\UpdatePostInput;

#[Controller('blog')]
final class BlogController
{
    #[Get('posts')]
    public function list(): Pagination
    {
        return new Pagination([], 0, 1, 10);
    }

    #[Get('posts/typed')]
    #[ApiOkResponse(description: 'One page of posts', schema: ['allOf' => [
        ['$ref' => Pagination::class],
        ['properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => BlogPost::class]]]],
    ]])]
    public function typed(): Pagination
    {
        return new Pagination([], 0, 1, 10);
    }

    #[Post('posts')]
    public function create(#[Body] CreatePostInput $dto): array
    {
        return ['title' => $dto->title, 'tags' => $dto->tags, 'ids' => $dto->ids];
    }

    #[Patch('posts/{id}')]
    public function update(#[Body] UpdatePostInput $dto): BlogPost
    {
        return new BlogPost;
    }

    /** @return list<array{name: string, posts: int}> */
    #[Get('stats')]
    #[ApiOkResponse(description: 'Tags with their number of posts')]
    public function stats(): array
    {
        return [];
    }
}
