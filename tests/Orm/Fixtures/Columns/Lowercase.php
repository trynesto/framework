<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Columns;

use Nesto\Orm\ValueTransformer;

final class Lowercase implements ValueTransformer
{
    public function to(mixed $value): mixed
    {
        return is_string($value) ? strtolower($value) : $value;
    }

    public function from(mixed $value): mixed
    {
        return $value;
    }
}
