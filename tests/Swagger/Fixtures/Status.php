<?php

declare(strict_types=1);

namespace Nesto\Tests\Swagger\Fixtures;

enum Status: string
{
    case Active = 'active';
    case Blocked = 'blocked';
}
