<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures;

use Nesto\Common\Attributes\Body;
use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Get;
use Nesto\Common\Attributes\Param;
use Nesto\Common\Attributes\Post;
use Nesto\Common\Attributes\UseInterceptors;
use Nesto\Orm\Attributes\Transactional;
use Nesto\Orm\TransactionalInterceptor;

#[Controller('cats')]
#[UseInterceptors(TransactionalInterceptor::class)]
final class CatsController
{
    public function __construct(private readonly CatsService $cats) {}

    #[Post]
    public function create(#[Body] array $body): Cat
    {
        return $this->cats->create($body);
    }

    #[Post('failing')]
    #[Transactional]
    public function failing(#[Body] array $body): Cat
    {
        $this->cats->create($body);
        throw new \RuntimeException('boom');
    }

    #[Get]
    public function index(): array
    {
        return $this->cats->all();
    }

    #[Get(':id')]
    public function show(#[Param('id')] int $id): Cat
    {
        return $this->cats->one($id);
    }
}
