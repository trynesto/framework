<?php

declare(strict_types=1);

namespace Nesto\Testing;

use Nesto\Common\Attributes\Module;

/** Empty root module that Test::createTestingModule() decorates with the metadata you pass. */
#[Module]
final class RootTestModule
{
}
