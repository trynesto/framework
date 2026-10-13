<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;

/** Inject the current Nesto\Http\Request (or PSR-7 ServerRequestInterface, by type). */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Req {}
