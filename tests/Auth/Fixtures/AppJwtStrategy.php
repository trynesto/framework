<?php

declare(strict_types=1);

namespace Nesto\Tests\Auth\Fixtures;

use Nesto\Auth\ExtractJwt;
use Nesto\Auth\Strategies\JwtStrategy;
use Nesto\Jwt\JwtService;

final class AppJwtStrategy extends JwtStrategy
{
    public function __construct(JwtService $jwt, private readonly UsersService $users)
    {
        parent::__construct($jwt, ['jwtFromRequest' => ExtractJwt::fromAuthHeaderAsBearerToken()]);
    }

    public function validate(array $payload): mixed
    {
        return $this->users->find((int) $payload['sub']);
    }
}
