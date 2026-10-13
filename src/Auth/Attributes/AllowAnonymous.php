<?php

declare(strict_types=1);

namespace Nesto\Auth\Attributes;

use Attribute;

/** Alias of #[IsPublic]: AuthGuard lets the request through even without credentials. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class AllowAnonymous {}
