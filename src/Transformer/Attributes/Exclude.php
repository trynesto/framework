<?php

declare(strict_types=1);

namespace Nesto\Transformer\Attributes;

use Attribute;

/**
 * Skips a property in both directions (or only one of them) — class-transformer's @Exclude().
 *
 *   #[Exclude]                        // never hydrated, never serialized
 *   public string $password;
 *
 *   #[Exclude(toPlainOnly: true)]     // can be hydrated from plain, but never appears in instanceToPlain()
 *   public string $secret;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Exclude
{
    public function __construct(
        public readonly bool $toPlainOnly = false,
        public readonly bool $toClassOnly = false,
    ) {}
}
