<?php

declare(strict_types=1);

namespace Nesto\Tests\Swagger\Fixtures;

final class AddressDto
{
    public string $street;
    public ?string $zip = null;
}
