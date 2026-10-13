<?php

declare(strict_types=1);

namespace Nesto\Tests\Core\Exports;

use Nesto\Common\Attributes\Injectable;
use Nesto\Common\Attributes\Module;
use Nesto\Common\Contracts\DynamicModuleFactory;
use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;
use Nesto\Core\NestoFactory;
use Nesto\Core\UnknownDependencyException;
use Nesto\Core\UnknownExportException;
use PHPUnit\Framework\TestCase;

#[Injectable]
final class CatsService
{
}

#[Injectable]
final class DogsService
{
}

#[Module(providers: [CatsService::class], exports: [CatsService::class])]
final class CatsModule
{
}

#[Module(providers: [CatsService::class])]
final class PrivateCatsModule
{
}

/** Re-exports an imported module: its importers see CatsModule's exports. */
#[Module(imports: [CatsModule::class], exports: [CatsModule::class])]
final class CoreModule
{
}

#[Injectable]
final class NeedsCats
{
    public function __construct(public readonly CatsService $cats) {}
}

#[Module(imports: [CoreModule::class], providers: [NeedsCats::class])]
final class ReExportAppModule
{
}

#[Module(imports: [PrivateCatsModule::class], providers: [NeedsCats::class])]
final class PrivateAppModule
{
}

#[Module(providers: [CatsService::class], exports: [DogsService::class])]
final class ExportsUnknownProviderModule
{
}

/** Exporting a provider that only came in through an import (not the module itself) is not allowed in Nest. */
#[Module(imports: [CatsModule::class], exports: [CatsService::class])]
final class ExportsImportedProviderModule
{
}

#[Module(exports: [CatsModule::class])]
final class ExportsNotImportedModule
{
}

#[Module(providers: [new Provider('CATS_LIMIT', useValue: 9)], exports: [new Provider('CATS_LIMIT', useValue: 9)])]
final class ExportsProviderObjectModule
{
}

final class SettingsModule implements DynamicModuleFactory
{
    public function toDynamicModule(): DynamicModule
    {
        return new DynamicModule(module: self::class, providers: [new Provider('SETTINGS', useValue: ['a' => 1])], exports: ['SETTINGS']);
    }
}

#[Module(imports: [new SettingsModule()], exports: [new SettingsModule()])]
final class ReExportsDynamicModule
{
}

#[Module(imports: [ReExportsDynamicModule::class, ExportsProviderObjectModule::class])]
final class DynamicAppModule
{
}

final class ModuleExportsTest extends TestCase
{
    public function testAModuleCanReExportAnImportedModule(): void
    {
        $app = NestoFactory::create(ReExportAppModule::class)->init();

        self::assertInstanceOf(CatsService::class, $app->get(NeedsCats::class)->cats);
    }

    public function testProvidersThatAreNotExportedStayPrivate(): void
    {
        $this->expectException(UnknownDependencyException::class);
        $this->expectExceptionMessage('does not export it');

        NestoFactory::create(PrivateAppModule::class)->init();
    }

    public function testExportingSomethingThatIsNotAProviderFails(): void
    {
        $this->expectException(UnknownExportException::class);
        $this->expectExceptionMessage('cannot export "' . DogsService::class . '" from module ' . ExportsUnknownProviderModule::class);

        NestoFactory::create(ExportsUnknownProviderModule::class);
    }

    public function testExportingAProviderOfAnImportedModuleFails(): void
    {
        $this->expectException(UnknownExportException::class);
        $this->expectExceptionMessage('or export the module that provides it');

        NestoFactory::create(ExportsImportedProviderModule::class);
    }

    public function testExportingAModuleThatIsNotImportedFails(): void
    {
        $this->expectException(UnknownExportException::class);
        $this->expectExceptionMessage('Add ' . CatsModule::class . ' to ' . ExportsNotImportedModule::class . "'s imports");

        NestoFactory::create(ExportsNotImportedModule::class);
    }

    public function testProviderObjectsAndDynamicModulesCanBeExported(): void
    {
        $app = NestoFactory::create(DynamicAppModule::class)->init();

        self::assertSame(['a' => 1], $app->get('SETTINGS'));
        self::assertSame(9, $app->get('CATS_LIMIT'));
    }
}
