<?php

declare(strict_types=1);

namespace Nesto\Auth\Attributes;

use Attribute;

/**
 * Marks a handler or a whole controller as reachable without authentication, even when AuthGuard applies
 * (globally or on the class). `Public` is a reserved word in PHP, hence the name; #[AllowAnonymous] is an alias.
 *
 *   #[Get('health')] #[IsPublic] public function health(): array { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class IsPublic {}
