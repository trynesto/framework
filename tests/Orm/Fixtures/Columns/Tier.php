<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Columns;

enum Tier: string
{
    case Free = 'free';
    case Pro = 'pro';
    case Team = 'team';
}
