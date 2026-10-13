<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;

/** Inject the mutable Nesto\Http\Response the handler may write to. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Res {}
