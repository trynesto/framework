<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Bank;

use Nesto\Common\Attributes\Injectable;

#[Injectable]
final class AuditTrail
{
    /** @var list<string> */
    public array $lines = [];
}
