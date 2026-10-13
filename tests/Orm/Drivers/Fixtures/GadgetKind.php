<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers\Fixtures;

enum GadgetKind: string
{
    case Tool = 'tool';
    case Toy = 'toy';
}
