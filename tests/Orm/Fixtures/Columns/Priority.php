<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Columns;

enum Priority: int
{
    case Low = 1;
    case High = 2;
}
