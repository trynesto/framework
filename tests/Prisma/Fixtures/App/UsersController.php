<?php

declare(strict_types=1);

namespace Nesto\Tests\Prisma\Fixtures\App;

use Nesto\Common\Attributes\Body;
use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Get;
use Nesto\Common\Attributes\Param;
use Nesto\Common\Attributes\Post;

#[Controller('users')]
final class UsersController
{
    public function __construct(private readonly UsersService $users) {}

    #[Post]
    public function create(#[Body] array $body): array
    {
        return $this->users->create($body);
    }

    #[Get]
    public function index(): array
    {
        return $this->users->all();
    }

    #[Post(':id/posts')]
    public function addPost(#[Param('id')] string $id, #[Body] array $body): array
    {
        return $this->users->addPost((int) $id, $body);
    }
}
