<?php

declare(strict_types=1);

namespace Nesto\Tests\Auth\Fixtures;

use Nesto\Auth\AuthGuard;

final class JwtAuthGuard extends AuthGuard
{
    protected string $strategy = 'jwt';
}
