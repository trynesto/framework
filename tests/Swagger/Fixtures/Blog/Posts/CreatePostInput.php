<?php

declare(strict_types=1);

namespace Nesto\Tests\Swagger\Fixtures\Blog\Posts;

use Nesto\Http\File;
use Nesto\Validation\Attributes\ArrayMaxSize;
use Nesto\Validation\Attributes\IsArray;
use Nesto\Validation\Attributes\IsImage;
use Nesto\Validation\Attributes\IsInt;
use Nesto\Validation\Attributes\IsNotEmpty;
use Nesto\Validation\Attributes\IsOptional;
use Nesto\Validation\Attributes\IsString;
use Nesto\Validation\Attributes\MaxLength;

/** Sent as multipart/form-data. */
final class CreatePostInput
{
    #[IsString, IsNotEmpty]
    public string $title;

    #[IsImage(maxSize: '1MB')]
    public File $image;

    /** @var list<string> */
    #[IsOptional, IsArray, ArrayMaxSize(5), IsString(each: true), MaxLength(50, each: true)]
    public array $tags = [];

    /** @var list<int> */
    #[IsOptional, IsInt(each: true)]
    public array $ids = [];
}
