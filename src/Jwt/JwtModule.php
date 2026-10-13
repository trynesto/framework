<?php

declare(strict_types=1);

namespace Nesto\Jwt;

use Nesto\Common\Contracts\DynamicModuleFactory;
use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;

/**
 * Nest's JwtModule. Provides (and exports) JwtService.
 *
 * In a #[Module] attribute:
 *   #[Module(imports: [new JwtModule(secret: 'change-me', signOptions: ['expiresIn' => '1h'], global: true)])]
 *
 * At runtime:
 *   JwtModule::register(['secret' => 'x', 'signOptions' => ['expiresIn' => '7d']]);
 *   JwtModule::registerAsync([
 *       'useFactory' => fn (ConfigService $config) => ['secret' => $config->getOrThrow('JWT_SECRET')],
 *       'inject'     => [ConfigService::class],   // optional: without it the closure is autowired by type
 *       'global'     => true,
 *   ]);
 *
 * RSA keys:
 *   new JwtModule(algorithm: 'RS256', privateKey: file_get_contents('private.pem'), publicKey: file_get_contents('public.pem'))
 */
final class JwtModule implements DynamicModuleFactory
{
    public const OPTIONS = 'JWT_MODULE_OPTIONS';

    /**
     * @param array<string, mixed> $signOptions   expiresIn, notBefore, issuer, audience, subject, jwtid, algorithm
     * @param array<string, mixed> $verifyOptions algorithms, issuer, audience, clockTolerance, ignoreExpiration, maxAge
     */
    public function __construct(
        public readonly ?string $secret = null,
        public readonly array $signOptions = [],
        public readonly bool $global = false,
        public readonly ?string $publicKey = null,
        public readonly ?string $privateKey = null,
        public readonly ?string $algorithm = null,
        public readonly array $verifyOptions = [],
    ) {}

    /**
     * @param array{secret?: string, signOptions?: array, global?: bool, publicKey?: string, privateKey?: string, algorithm?: string, verifyOptions?: array} $options
     */
    public static function register(array $options = []): DynamicModule
    {
        return (new self(...$options))->toDynamicModule();
    }

    /**
     * Resolve the options from other providers (ConfigService, ...).
     *
     * @param array{useFactory: callable|array|string, inject?: list<string>, imports?: list<mixed>, global?: bool} $options
     */
    public static function registerAsync(array $options): DynamicModule
    {
        if (! isset($options['useFactory'])) {
            throw new \InvalidArgumentException('JwtModule::registerAsync() requires a "useFactory" option.');
        }

        return new DynamicModule(
            module: self::class,
            imports: (array) ($options['imports'] ?? []),
            providers: [
                new Provider(self::OPTIONS, useFactory: $options['useFactory'], inject: (array) ($options['inject'] ?? [])),
                new Provider(JwtService::class, useFactory: fn (array $opts) => new JwtService($opts), inject: [self::OPTIONS]),
            ],
            exports: [JwtService::class, self::OPTIONS],
            global: (bool) ($options['global'] ?? false),
        );
    }

    public function toDynamicModule(): DynamicModule
    {
        $options = $this->toArray();

        return new DynamicModule(
            module: self::class,
            providers: [
                new Provider(self::OPTIONS, useValue: $options),
                new Provider(JwtService::class, useFactory: fn () => new JwtService($options)),
            ],
            exports: [JwtService::class, self::OPTIONS],
            global: $this->global,
        );
    }

    /** @return array<string, mixed> JwtService constructor options */
    public function toArray(): array
    {
        return array_filter([
            'secret' => $this->secret,
            'publicKey' => $this->publicKey,
            'privateKey' => $this->privateKey,
            'algorithm' => $this->algorithm,
            'signOptions' => $this->signOptions,
            'verifyOptions' => $this->verifyOptions,
        ], fn ($v) => $v !== null);
    }
}
