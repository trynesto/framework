<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Inheritance;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\Unique;

#[Entity('cti_dogs')]
#[Unique(['name', 'breed'])]
class CtiDog extends CtiBase
{
    #[Column]
    public string $breed;
}
