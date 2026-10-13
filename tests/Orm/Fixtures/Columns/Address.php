<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Columns;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Embedded;

final class Address
{
    #[Column(length: 80)]
    public string $city;

    #[Column(name: 'zip_code', length: 10, nullable: true)]
    public ?string $zip = null;

    #[Embedded(Geo::class)]
    public Geo $geo;
}
