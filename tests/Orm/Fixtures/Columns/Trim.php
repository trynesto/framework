<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Columns;

use Nesto\Orm\ValueTransformer;

final class Trim implements ValueTransformer
{
    public function to(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    public function from(mixed $value): mixed
    {
        return $value;
    }
}

/** Stores euros (float in PHP) as integer cents. */
