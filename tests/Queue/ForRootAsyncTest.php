<?php

declare(strict_types=1);

namespace Nesto\Tests\Queue;

use Nesto\Common\DynamicModule;
use Nesto\Common\Provider;
use Nesto\Core\NestoFactory;
use Nesto\Queue\Drivers\FileDriver;
use Nesto\Queue\Drivers\SyncDriver;
use Nesto\Queue\QueueDriver;
use Nesto\Queue\QueueModule;
use Nesto\Throttler\Storage\FileStorage;
use Nesto\Throttler\Storage\MemoryStorage;
use Nesto\Throttler\ThrottlerModule;
use PHPUnit\Framework\TestCase;

/** forRootAsync() of QueueModule and ThrottlerModule: options come from other providers. */
final class ForRootAsyncTest extends TestCase
{
    public static function queueOptions(array $settings): array
    {
        return ['driver' => $settings['queue'], 'path' => sys_get_temp_dir() . '/nesto-async-queue'];
    }

    public static function throttlerOptions(array $settings): array
    {
        return ['ttl' => 30, 'limit' => 7, 'storage' => $settings['throttle']];
    }

    private function app(array $settings): \Nesto\Core\NestoApplication
    {
        $settingsModule = new DynamicModule(SettingsHost::class, providers: [new Provider('SETTINGS', useValue: $settings)], exports: ['SETTINGS'], global: true);

        return NestoFactory::create(new DynamicModule(SettingsRoot::class, imports: [
            $settingsModule,
            QueueModule::forRootAsync(['useFactory' => [self::class, 'queueOptions'], 'inject' => ['SETTINGS']]),
            ThrottlerModule::forRootAsync(['useFactory' => [self::class, 'throttlerOptions'], 'inject' => ['SETTINGS']]),
        ]));
    }

    public function test_options_are_built_by_the_factory(): void
    {
        $app = $this->app(['queue' => 'file', 'throttle' => 'memory']);
        self::assertInstanceOf(FileDriver::class, $app->get(QueueDriver::class));
        self::assertInstanceOf(MemoryStorage::class, $app->get(\Nesto\Throttler\ThrottlerStorage::class));
        self::assertSame(7, $app->get(ThrottlerModule::OPTIONS)['limit']);

        $app = $this->app(['queue' => 'sync', 'throttle' => 'file:' . sys_get_temp_dir() . '/nesto-async-throttle']);
        self::assertInstanceOf(SyncDriver::class, $app->get(QueueDriver::class));
        self::assertInstanceOf(FileStorage::class, $app->get(\Nesto\Throttler\ThrottlerStorage::class));
    }
}

#[\Nesto\Common\Attributes\Module]
final class SettingsRoot {}

#[\Nesto\Common\Attributes\Module]
final class SettingsHost {}
