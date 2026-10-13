<?php

declare(strict_types=1);

namespace Nesto\Common;

/**
 * Nest-style provider definition: { provide, useClass | useValue | useFactory | useExisting }.
 *
 * Inside a #[Module] attribute (constant expressions only):
 *   new Provider('APP_NAME', useValue: 'Nesto')
 *   new Provider(Logger::class, useClass: FileLogger::class)
 *   new Provider('DB', useFactory: [DbFactory::class, 'create'], inject: [ConfigService::class])   // static method
 *   new Provider('DB', useFactory: DbFactory::class)                                                // invokable class, autowired
 *   new Provider(AliasToken::class, useExisting: CatsService::class)
 *
 * At runtime (DynamicModule, forRoot(), tests) closures work too:
 *   provide('DB')->useFactory(fn (ConfigService $c) => new PDO($c->get('dsn')), inject: [ConfigService::class])
 */
final class Provider
{
    private const UNSET = '__nesto_unset__';

    public bool $hasValue;

    /**
     * @param \Closure|array{0: class-string, 1: string}|class-string|null $useFactory
     * @param list<string> $inject tokens resolved and passed to the factory in order
     */
    public function __construct(
        public readonly string $provide,
        public ?string $useClass = null,
        public mixed $useValue = self::UNSET,
        public \Closure|array|string|null $useFactory = null,
        public array $inject = [],
        public ?string $useExisting = null,
        public Scope $scope = Scope::DEFAULT,
    ) {
        $this->hasValue = $useValue !== self::UNSET;
        if (! $this->hasValue) {
            $this->useValue = null;
        }
    }

    public static function class(string $class, Scope $scope = Scope::DEFAULT): self
    {
        return new self($class, useClass: $class, scope: $scope);
    }

    public function useClass(string $class): self
    {
        $this->useClass = $class;

        return $this;
    }

    public function useValue(mixed $value): self
    {
        $this->useValue = $value;
        $this->hasValue = true;

        return $this;
    }

    /** @param list<string> $inject */
    public function useFactory(\Closure|array|string $factory, array $inject = []): self
    {
        $this->useFactory = $factory;
        $this->inject = $inject;

        return $this;
    }

    public function useExisting(string $token): self
    {
        $this->useExisting = $token;

        return $this;
    }

    public function scope(Scope $scope): self
    {
        $this->scope = $scope;

        return $this;
    }

    /** Normalise anything allowed in `providers: []` into a Provider. */
    public static function normalize(string|self $definition): self
    {
        if ($definition instanceof self) {
            return $definition;
        }
        $scope = Scope::DEFAULT;
        if (class_exists($definition)) {
            $attr = (new \ReflectionClass($definition))->getAttributes(\Nesto\Common\Attributes\Injectable::class)[0] ?? null;
            $scope = $attr?->newInstance()->scope ?? Scope::DEFAULT;
        }

        return self::class($definition, $scope);
    }
}
