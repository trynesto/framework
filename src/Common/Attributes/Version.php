<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;

/**
 * URI versioning: #[Version('1')] prefixes the route with /v1 when versioning is enabled.
 *
 *   #[Version('1', '2')]            // served under /v1/... and /v2/...
 *   #[Version(VERSION_NEUTRAL)]     // served without a version segment, whatever the default version is
 *                                   // (Version::NEUTRAL is the same value)
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Version
{
    /** Nest's VERSION_NEUTRAL (also available as the global constant VERSION_NEUTRAL). */
    public const NEUTRAL = '__nesto_version_neutral__';

    /** @var list<string> */
    public readonly array $versions;

    public function __construct(string ...$versions)
    {
        $this->versions = array_values($versions);
    }
}
