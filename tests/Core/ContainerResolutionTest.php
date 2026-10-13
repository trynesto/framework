<?php

declare(strict_types=1);

namespace Nesto\Tests\Core\Resolution;

use Nesto\Common\Attributes\Inject;
use Nesto\Common\Attributes\Injectable;
use Nesto\Common\Attributes\Module;
use Nesto\Common\Attributes\Optional;
use Nesto\Common\Provider;
use Nesto\Common\Scope;
use Nesto\Core\NestoFactory;
use Nesto\Core\UnknownDependencyException;
use PHPUnit\Framework\TestCase;

// ---- encapsulation: a provider of another module is never silently re-created --------------------

#[Injectable]
final class Counter
{
    public int $count = 0;
}

#[Injectable]
final class Unregistered
{
}

#[Module(providers: [Counter::class])]
final class PrivateCounterModule
{
}

#[Module(providers: [Counter::class], exports: [Counter::class])]
final class ExportedCounterModule
{
}

#[Injectable]
final class CounterConsumer
{
    public function __construct(public readonly Counter $counter) {}
}

#[Module(imports: [PrivateCounterModule::class], providers: [CounterConsumer::class])]
final class ImportsPrivateModule
{
}

#[Module(imports: [PrivateCounterModule::class])]
final class NotImportingRoot
{
}

#[Module(imports: [NotImportingRoot::class], providers: [CounterConsumer::class])]
final class MissingImportModule
{
}

#[Module(imports: [ExportedCounterModule::class], providers: [CounterConsumer::class])]
final class ImportsExportedModule
{
}

#[Injectable]
final class UsesUnregistered
{
    public function __construct(public readonly Unregistered $dep) {}
}

#[Injectable]
final class AlsoUsesUnregistered
{
    public function __construct(public readonly Unregistered $dep) {}
}

#[Module(providers: [UsesUnregistered::class])]
final class FirstAutowireModule
{
}

#[Module(imports: [FirstAutowireModule::class], providers: [AlsoUsesUnregistered::class])]
final class AutowireModule
{
}

// ---- default values on class-typed constructor parameters -----------------------------------------

final class Plain
{
    public function __construct(public readonly string $name = 'default') {}
}

final class WithDefaults
{
    public function __construct(
        public readonly Plain $plain = new Plain('from-default'),
        public readonly ?Plain $nullable = null,
        #[Optional] public readonly ?Plain $optional = null,
        #[Inject('MISSING')] public readonly string $token = 'token-default',
    ) {}
}

final class NeedsPlain
{
    public function __construct(public readonly Plain $plain) {}
}

#[Injectable]
final class PrivateWithDefault
{
    public function __construct(public readonly Counter $counter = new Counter()) {}
}

#[Module(providers: [WithDefaults::class, new Provider(WithDefaults::class . '#t', useClass: WithDefaults::class, scope: Scope::TRANSIENT)])]
final class DefaultsModule
{
}

#[Module(providers: [new Provider(Plain::class, useValue: new Plain('registered')), WithDefaults::class])]
final class RegisteredWinsModule
{
}

#[Module(providers: [NeedsPlain::class])]
final class NoDefaultModule
{
}

#[Module(imports: [PrivateCounterModule::class], providers: [PrivateWithDefault::class])]
final class PrivateWithDefaultModule
{
}

final class ContainerResolutionTest extends TestCase
{
    public function test_a_non_exported_provider_of_an_imported_module_is_not_autowired_again(): void
    {
        try {
            NestoFactory::createApplicationContext(ImportsPrivateModule::class)->init();
            self::fail('Expected UnknownDependencyException');
        } catch (UnknownDependencyException $e) {
            self::assertStringContainsString('argument $counter of ' . CounterConsumer::class, $e->getMessage());
            self::assertStringContainsString(
                '"' . Counter::class . '" is a provider of module ' . PrivateCounterModule::class . ' but ' . PrivateCounterModule::class . ' does not export it',
                $e->getMessage()
            );
        }
    }

    public function test_a_provider_of_a_module_that_is_not_imported_is_not_autowired_again(): void
    {
        $this->expectException(UnknownDependencyException::class);
        $this->expectExceptionMessage('is a provider of module ' . PrivateCounterModule::class);
        NestoFactory::createApplicationContext(MissingImportModule::class)->init();
    }

    public function test_an_exported_provider_is_shared(): void
    {
        $app = NestoFactory::createApplicationContext(ImportsExportedModule::class);
        $counter = $app->select(ExportedCounterModule::class)->get(Counter::class);

        self::assertSame($counter, $app->get(CounterConsumer::class)->counter);
    }

    public function test_an_injectable_class_registered_nowhere_is_not_autowired(): void
    {
        // Nest: #[Injectable] only marks a class as a provider; it must still be listed in a module's providers.
        $this->expectException(UnknownDependencyException::class);
        $this->expectExceptionMessage('Make sure "' . Unregistered::class . '" is listed in the providers of this module');

        NestoFactory::createApplicationContext(AutowireModule::class)->get(UsesUnregistered::class);
    }

    public function test_unresolvable_class_typed_parameters_fall_back_to_their_default(): void
    {
        $app = NestoFactory::createApplicationContext(DefaultsModule::class);
        $service = $app->get(WithDefaults::class);

        self::assertSame('from-default', $service->plain->name);
        self::assertNull($service->nullable);
        self::assertNull($service->optional);
        self::assertSame('token-default', $service->token);
    }

    public function test_default_objects_are_created_per_instance(): void
    {
        $app = NestoFactory::createApplicationContext(DefaultsModule::class);

        self::assertNotSame($app->get(WithDefaults::class . '#t')->plain, $app->get(WithDefaults::class . '#t')->plain);
    }

    public function test_a_registered_provider_wins_over_the_default(): void
    {
        $app = NestoFactory::createApplicationContext(RegisteredWinsModule::class);
        $service = $app->get(WithDefaults::class);

        self::assertSame('registered', $service->plain->name);
        self::assertSame('registered', $service->nullable?->name);
        self::assertSame('registered', $service->optional?->name);
    }

    public function test_a_default_is_used_instead_of_a_provider_the_module_cannot_see(): void
    {
        $app = NestoFactory::createApplicationContext(PrivateWithDefaultModule::class);

        self::assertNotSame($app->select(PrivateCounterModule::class)->get(Counter::class), $app->get(PrivateWithDefault::class)->counter);
    }

    public function test_a_class_typed_parameter_without_default_still_fails(): void
    {
        $this->expectException(UnknownDependencyException::class);
        $this->expectExceptionMessage('argument $plain of ' . NeedsPlain::class);
        NestoFactory::createApplicationContext(NoDefaultModule::class)->init();
    }
}
