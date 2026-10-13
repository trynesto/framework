<?php

declare(strict_types=1);

namespace Nesto\Auth;

use Nesto\Common\Contracts\DynamicModuleFactory;
use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;
use Nesto\Core\Container;

/**
 * Nest's PassportModule. Provides the StrategyRegistry (and AuthGuard / RolesGuard for DI) and is global
 * by default so `#[UseGuards(AuthGuard::class)]` works from any module.
 *
 *   #[Module(imports: [new AuthModule(defaultStrategy: 'jwt')])]
 *   AuthModule::register(['defaultStrategy' => 'jwt']);
 *
 * Strategies are ordinary providers anywhere in the module graph:
 *   #[Module(providers: [AppJwtStrategy::class, AppLocalStrategy::class])]
 */
final class AuthModule implements DynamicModuleFactory
{
    public const OPTIONS = 'AUTH_MODULE_OPTIONS';

    public function __construct(
        public readonly ?string $defaultStrategy = null,
        public readonly bool $global = true,
    ) {}

    /** @param array{defaultStrategy?: ?string, global?: bool} $options */
    public static function register(array $options = []): DynamicModule
    {
        return (new self(...$options))->toDynamicModule();
    }

    public function toDynamicModule(): DynamicModule
    {
        $default = $this->defaultStrategy;

        return new DynamicModule(
            module: self::class,
            providers: [
                new Provider(self::OPTIONS, useValue: ['defaultStrategy' => $default, 'global' => $this->global]),
                new Provider(StrategyRegistry::class, useFactory: fn (Container $c) => new StrategyRegistry($c, $default)),
                AuthGuard::class,
                RolesGuard::class,
            ],
            exports: [self::OPTIONS, StrategyRegistry::class, AuthGuard::class, RolesGuard::class],
            global: $this->global,
        );
    }
}
