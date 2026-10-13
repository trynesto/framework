<?php

declare(strict_types=1);

namespace Nesto\Tests\Swagger\Fixtures\Blog\Posts;

use Nesto\Validation\Attributes\IsNotEmpty;
use Nesto\Validation\Attributes\IsOptional;
use Nesto\Validation\Attributes\IsString;

final class UpdatePostInput
{
    #[IsString, IsNotEmpty, IsOptional]
    public ?string $title = null;

    #[IsOptional, IsNotEmpty]
    public string $body;

    /** @var list<string>|null */
    #[IsOptional, IsString(each: true)]
    public ?array $tags = null;
}
