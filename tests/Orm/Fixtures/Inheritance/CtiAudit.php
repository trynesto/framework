<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Inheritance;

use Nesto\Orm\Attributes\Column;

final class CtiAudit
{
    #[Column(nullable: true)]
    public ?string $by = null;

    #[Column(nullable: true)]
    public ?string $note = null;
}
