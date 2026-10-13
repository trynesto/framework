<?php

declare(strict_types=1);

namespace Nesto\Testing;

/**
 * Entry point of the testing package (Nest's `Test.createTestingModule()`).
 *
 *   $module = Test::createTestingModule([
 *       'imports' => [CatsModule::class],
 *       'controllers' => [CatsController::class],
 *       'providers' => [CatsService::class],
 *   ])
 *   ->overrideProvider(CatsService::class)->useValue(new FakeCatsService())
 *   ->overrideGuard(AuthGuard::class)->useValue(new AllowAllGuard())
 *   ->compile();
 *
 *   $module->get(CatsController::class);
 *   $module->request()->get('/cats')->expect(200)->assertJsonCount(2);
 */
final class Test
{
    /** @param array{imports?: list<mixed>, controllers?: list<class-string>, providers?: list<mixed>, exports?: list<string>} $metadata */
    public static function createTestingModule(array $metadata = []): TestingModuleBuilder
    {
        return new TestingModuleBuilder($metadata);
    }
}
