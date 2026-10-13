<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures;

enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
