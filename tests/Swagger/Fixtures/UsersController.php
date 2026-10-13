<?php

declare(strict_types=1);

namespace Nesto\Tests\Swagger\Fixtures;

use Nesto\Common\Attributes\Body;
use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Delete;
use Nesto\Common\Attributes\Get;
use Nesto\Common\Attributes\Headers;
use Nesto\Common\Attributes\HttpCode;
use Nesto\Common\Attributes\Param;
use Nesto\Common\Attributes\Post;
use Nesto\Common\Attributes\Query;
use Nesto\Swagger\Attributes\ApiExcludeEndpoint;
use Nesto\Swagger\Attributes\ApiExtraModels;
use Nesto\Swagger\Attributes\ApiNotFoundResponse;
use Nesto\Swagger\Attributes\ApiOkResponse;
use Nesto\Swagger\Attributes\ApiParam;
use Nesto\Swagger\Attributes\ApiQuery;
use Nesto\Swagger\Attributes\ApiSecurity;
use Nesto\Swagger\Attributes\ApiTags;

#[Controller('users')]
#[ApiTags('users')]
#[ApiSecurity('api-key')]
#[ApiExtraModels(ErrorDto::class)]
final class UsersController
{
    #[Get]
    public function list(#[Query] ListUsersQuery $query, #[Query('sort')] string $sort = 'id', #[Headers('x-tenant')] ?string $tenant = null): array
    {
        return [];
    }

    #[Get('{id:\d+}')]
    #[ApiParam(name: 'id', description: 'User id')]
    #[ApiOkResponse(type: CreateUserDto::class)]
    #[ApiNotFoundResponse(description: 'No such user')]
    public function show(#[Param('id')] int $id): CreateUserDto
    {
        return new CreateUserDto;
    }

    #[Post]
    public function create(#[Body] CreateUserDto $dto): CreateUserDto
    {
        return $dto;
    }

    /** Give a user a role. @deprecated use PATCH */
    #[Post(':id/roles')]
    #[HttpCode(200)]
    #[ApiQuery(name: 'notify', type: 'boolean', description: 'Send an email')]
    public function addRole(#[Param('id')] int $id, #[Body('role')] string $role, #[Body('expires')] ?int $expires = null): array
    {
        return [];
    }

    #[Delete('{id}')]
    #[HttpCode(204)]
    public function remove(#[Param('id')] int $id): void {}

    #[Get('secret')]
    #[ApiExcludeEndpoint]
    public function hidden(): array
    {
        return [];
    }
}
