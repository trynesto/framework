<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Columns;

use Nesto\Orm\Attributes\Column;

final class Geo
{
    #[Column(type: 'int', nullable: true)]
    public ?int $lat = null;

    #[Column(type: 'int', nullable: true)]
    public ?int $lng = null;
}
