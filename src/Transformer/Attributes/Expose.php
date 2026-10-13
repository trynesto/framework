<?php

declare(strict_types=1);

namespace Nesto\Transformer\Attributes;

use Attribute;

/**
 * Marks a property as exposed and optionally renames it / restricts it to groups — class-transformer's @Expose().
 *
 *   #[Expose(name: 'first_name')]      // plain key "first_name" ↔ property $firstName
 *   public string $firstName;
 *
 *   #[Expose(groups: ['admin'])]       // only present when ['groups' => ['admin']] is passed
 *   public string $internalNote;
 *
 * With the `excludeExtraneousValues` option only #[Expose]d properties are hydrated / serialized.
 *
 * On a public method without required parameters it exposes a computed value (class-transformer's
 * `@Expose() get fullName()`, Laravel's accessor + `$appends`); the key is the method name without its `get`
 * prefix (and `Attribute` suffix for `getXxxAttribute()`) unless `name` is given. A computed key equal to a
 * property's key replaces that property in the output (e.g. `getImages()` decorating `$images`).
 * Methods are serialized only (never hydrated):
 *
 *   #[Expose]                          // "excerpt"
 *   public function getExcerpt(): string { ... }
 *
 *   #[Expose(name: 'readingTime', groups: ['detail'])]
 *   public function minutesToRead(): int { ... }
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class Expose
{
    /**
     * @param string|null  $name   key used in the plain representation
     * @param list<string> $groups serialization groups this property belongs to
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly array $groups = [],
        public readonly bool $toPlainOnly = false,
        public readonly bool $toClassOnly = false,
    ) {}
}
