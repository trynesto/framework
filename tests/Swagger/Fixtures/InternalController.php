<?php

declare(strict_types=1);

namespace Nesto\Tests\Swagger\Fixtures;

use Nesto\Common\Attributes\Controller;
use Nesto\Common\Attributes\Get;
use Nesto\Swagger\Attributes\ApiExcludeController;

#[Controller('internal')]
#[ApiExcludeController]
final class InternalController
{
    #[Get]
    public function index(): array
    {
        return [];
    }
}
