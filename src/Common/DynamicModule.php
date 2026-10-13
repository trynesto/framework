<?php

declare(strict_types=1);

namespace Nesto\Common;

/**
 * Returned by static `forRoot()` / `forFeature()` methods on a module class.
 * Carries extra providers/imports/exports merged on top of the module's #[Module] attribute.
 */
final class DynamicModule
{
    /**
     * @param class-string                     $module
     * @param list<class-string|DynamicModule> $imports
     * @param list<class-string>               $controllers
     * @param list<class-string|Provider>      $providers
     * @param list<string>                     $exports
     */
    public function __construct(
        public readonly string $module,
        public readonly array $imports = [],
        public readonly array $controllers = [],
        public readonly array $providers = [],
        public readonly array $exports = [],
        public readonly bool $global = false,
    ) {}
}
