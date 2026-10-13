<?php

declare(strict_types=1);

namespace Nesto\Testing;

/**
 * Fluent target of overrideProvider()/overrideGuard()/...: pick how the replacement is produced.
 *
 *   ->overrideProvider('CONFIG')->useValue(['debug' => true])
 *   ->overrideProvider(Mailer::class)->useClass(FakeMailer::class)
 *   ->overrideProvider(Clock::class)->useFactory(fn () => new FrozenClock('2024-01-01'))
 */
final class OverrideBy
{
    /** @param \Closure(mixed): TestingModuleBuilder $apply */
    public function __construct(private readonly \Closure $apply) {}

    public function useValue(mixed $value): TestingModuleBuilder
    {
        return ($this->apply)(['type' => 'value', 'value' => $value]);
    }

    /** @param class-string $class */
    public function useClass(string $class): TestingModuleBuilder
    {
        return ($this->apply)(['type' => 'class', 'class' => $class]);
    }

    /** @param list<string> $inject tokens resolved and passed to the factory in order */
    public function useFactory(\Closure $factory, array $inject = []): TestingModuleBuilder
    {
        return ($this->apply)(['type' => 'factory', 'factory' => $factory, 'inject' => $inject]);
    }
}
