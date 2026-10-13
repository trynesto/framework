<?php

declare(strict_types=1);

namespace Nesto\Tests\Auth\Fixtures;

use Nesto\Auth\Attributes\CurrentUser;
use Nesto\Auth\Attributes\IsPublic;
use Nesto\Auth\Attributes\Roles;
use Nesto\Auth\AuthGuard;
use Nesto\Auth\Hash;
use Nesto\Auth\RolesGuard;
use Nesto\Common\Attributes\Body;
use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Get;
use Nesto\Common\Attributes\Post;
use Nesto\Common\Attributes\UseGuards;
use Nesto\Common\Exceptions\UnauthorizedException;
use Nesto\Jwt\JwtService;
use Nesto\Swagger\Attributes\ApiBearerAuth;
use Nesto\Swagger\Attributes\ApiCreatedResponse;
use Nesto\Swagger\Attributes\ApiOperation;
use Nesto\Swagger\Attributes\ApiTags;
use Nesto\Swagger\Attributes\ApiUnauthorizedResponse;

#[Controller]
#[ApiTags('auth')]
final class AuthController
{
    public function __construct(private readonly JwtService $jwt, private readonly UsersService $users) {}

    #[Post('auth/login')]
    #[ApiOperation(summary: 'Log in', description: 'Returns a JWT access token')]
    #[ApiCreatedResponse(description: 'Token issued')]
    #[ApiUnauthorizedResponse(description: 'Bad credentials')]
    public function login(#[Body] LoginDto $dto): array
    {
        $user = $this->users->findByUsername($dto->username);
        if ($user === null || ! Hash::check($dto->password, $user['password'])) {
            throw new UnauthorizedException('Invalid credentials');
        }

        return ['access_token' => $this->jwt->sign(['sub' => $user['id'], 'username' => $user['username']])];
    }

    #[Get('profile')]
    #[UseGuards(new AuthGuard('jwt'))]
    #[ApiBearerAuth]
    public function profile(#[CurrentUser] array $user): array
    {
        return $user;
    }

    #[Get('profile/id')]
    #[UseGuards(JwtAuthGuard::class)]
    #[ApiBearerAuth]
    public function profileId(#[CurrentUser('id')] int $id): array
    {
        return ['id' => $id];
    }

    #[Get('admin')]
    #[UseGuards(AuthGuard::class, RolesGuard::class)]
    #[Roles('admin')]
    #[ApiBearerAuth]
    public function admin(#[CurrentUser('username')] string $username): array
    {
        return ['admin' => $username];
    }

    #[Get('optional')]
    #[UseGuards(new AuthGuard('jwt'))]
    #[IsPublic]
    public function optional(#[CurrentUser] ?array $user): array
    {
        return ['user' => $user['username'] ?? null];
    }
}
