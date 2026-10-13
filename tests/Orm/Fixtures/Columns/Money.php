<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Columns;

final class Money
{
    public function __construct(public readonly string $amount) {}
}

/** Custom type "money": DECIMAL(19, 4) ↔ Money. */
