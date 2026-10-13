<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Inheritance;

use Nesto\Orm\Attributes\BeforeInsert;
use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;

#[Entity('cti_cats')]
class CtiCat extends CtiBase
{
    #[Column]
    public int $lives = 9;

    #[BeforeInsert]
    public function beforeInsertCat(): void
    {
        $this->calls[] = 'cat insert';
    }
}
