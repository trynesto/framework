<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Inheritance;

use Nesto\Orm\Attributes\ChildEntity;
use Nesto\Orm\Attributes\Column;

/** Discriminator value defaults to the short class name ("StiVideo"). */
#[ChildEntity]
class StiVideo extends StiContent
{
    #[Column]
    public int $duration;
}
