<?php

declare(strict_types=1);

namespace Nesto\Tests\Swagger\Fixtures;

use Nesto\Swagger\Attributes\ApiPropertyOptional;

final class ListUsersQuery
{
    #[ApiPropertyOptional(description: 'Page number', minimum: 1)]
    public int $page = 1;

    public ?string $search = null;
}
