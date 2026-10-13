<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

use Attribute;
use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;

#[Attribute(Attribute::TARGET_CLASS)]
final class Module
{
    /**
     * Attribute arguments must be constant expressions, so:
     *   imports:   [CatsModule::class, new ConfigModule(isGlobal: true), [OrmModule::class, 'forFeature', [[Cat::class]]]]
     *   providers: [CatsService::class, new Provider('APP_NAME', useValue: 'Nesto')]
     *
     * @param list<class-string|DynamicModule|object|array> $imports
     * @param list<class-string>               $controllers
     * @param list<class-string|Provider>      $providers
     * @param list<string>                     $exports     provider tokens or module classes re-exported
     */
    public function __construct(
        public readonly array $imports = [],
        public readonly array $controllers = [],
        public readonly array $providers = [],
        public readonly array $exports = [],
    ) {}
}
