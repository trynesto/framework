<?php

declare(strict_types=1);

namespace Nesto\Tests\Swagger\Fixtures\Blog;

use Nesto\Common\Attributes\Module;

#[Module(controllers: [BlogController::class])]
final class BlogSwaggerModule {}
