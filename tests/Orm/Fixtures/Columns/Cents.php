<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Columns;

use Nesto\Orm\ValueTransformer;

final class Cents implements ValueTransformer
{
    public function to(mixed $value): mixed
    {
        return $value === null ? null : (int) round($value * 100);
    }

    public function from(mixed $value): mixed
    {
        return $value === null ? null : ((int) $value) / 100;
    }
}
