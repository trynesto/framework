<?php

declare(strict_types=1);

namespace Nesto\Tests\Auth\Fixtures;

use Nesto\Swagger\Attributes\ApiProperty;

/** Credentials for POST /auth/login. */
final class LoginDto
{
    #[ApiProperty(example: 'alice', minLength: 2)]
    public string $username;

    #[ApiProperty(example: 'secret', format: 'password')]
    public string $password;
}
