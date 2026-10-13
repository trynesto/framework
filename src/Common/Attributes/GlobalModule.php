<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;

/** Nest's @Global(): the module's exports are visible everywhere without importing it. */
#[Attribute(Attribute::TARGET_CLASS)]
final class GlobalModule {}
