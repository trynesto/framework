<?php

declare(strict_types=1);

namespace Nesto\Auth\Attributes;

use Attribute;

/**
 * Roles required by RolesGuard. Values may be strings or backed enums.
 *
 *   #[Roles('admin', 'editor')]        // any of these
 *   #[Roles(Role::Admin)]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Roles
{
    /** @var list<string> */
    public readonly array $roles;

    public function __construct(string|\BackedEnum ...$roles)
    {
        $this->roles = array_values(array_map(fn ($r) => $r instanceof \BackedEnum ? (string) $r->value : $r, $roles));
    }
}
