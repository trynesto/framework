<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;

/** Dependency may be missing from the container; null (or the default) is injected instead of throwing. */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Optional {}
