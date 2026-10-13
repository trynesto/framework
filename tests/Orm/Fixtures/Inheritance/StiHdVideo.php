<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Inheritance;

use Nesto\Orm\Attributes\ChildEntity;
use Nesto\Orm\Attributes\Column;

/** A child of a child: StiVideo's repository sees these rows too. */
#[ChildEntity('hd')]
class StiHdVideo extends StiVideo
{
    #[Column(length: 16)]
    public string $resolution;
}
