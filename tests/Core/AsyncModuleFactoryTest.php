<?php

declare(strict_types=1);

namespace Nesto\Tests\Core\AsyncFactories;

use Nesto\Cache\CacheModule;
use Nesto\Common\Attributes\Module;
use Nesto\Common\DynamicModule;
use Nesto\Core\NestoApplication;
use Nesto\Core\NestoFactory;
use Nesto\Jwt\JwtModule;
use Nesto\Jwt\JwtService;
use Nesto\Orm\DataSource;
use Nesto\Orm\OrmModule;
use Nesto\Queue\QueueModule;
use Nesto\Throttler\ThrottlerModule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class Settings
{
    public int $ttl = 42;
}

#[Module(providers: [Settings::class], exports: [Settings::class])]
final class SettingsModule
{
}

#[Module]
final class AsyncRoot
{
}

/** Options factories of the *Async() modules: `inject` is optional (as in Nest). */
final class AsyncModuleFactoryTest extends TestCase
{
    public static function staticFactory(Settings $settings): array
    {
        return self::options($settings->ttl);
    }

    private static function options(int $ttl): array
    {
        return ['ttl' => $ttl, 'limit' => 5, 'secret' => 'shh', 'type' => 'sqlite', 'database' => ':memory:', 'driver' => 'sync'];
    }

    /** @return iterable<string, array{\Closure|array, int}> */
    public static function factories(): iterable
    {
        yield 'closure without parameters' => [fn () => self::options(7), 7];
        yield 'closure autowired by type' => [fn (Settings $s) => self::options($s->ttl), 42];
        yield 'static method autowired by type' => [[self::class, 'staticFactory'], 42];
    }

    private function boot(DynamicModule $module): NestoApplication
    {
        return NestoFactory::createApplicationContext(new DynamicModule(AsyncRoot::class, imports: [$module]))->init();
    }

    #[DataProvider('factories')]
    public function test_cache_register_async_without_inject(\Closure|array $factory, int $ttl): void
    {
        $app = $this->boot(CacheModule::registerAsync(['imports' => [SettingsModule::class], 'useFactory' => $factory]));

        self::assertSame($ttl, $app->get(CacheModule::OPTIONS)['ttl']);
        self::assertInstanceOf(CacheInterface::class, $app->get(CacheInterface::class));
    }

    #[DataProvider('factories')]
    public function test_jwt_register_async_without_inject(\Closure|array $factory, int $ttl): void
    {
        $app = $this->boot(JwtModule::registerAsync(['imports' => [SettingsModule::class], 'useFactory' => $factory]));

        self::assertSame($ttl, $app->get(JwtModule::OPTIONS)['ttl']);
        self::assertInstanceOf(JwtService::class, $app->get(JwtService::class));
    }

    #[DataProvider('factories')]
    public function test_throttler_for_root_async_without_inject(\Closure|array $factory, int $ttl): void
    {
        $app = $this->boot(ThrottlerModule::forRootAsync(['imports' => [SettingsModule::class], 'useFactory' => $factory]));

        self::assertSame($ttl, $app->get(ThrottlerModule::OPTIONS)['ttl']);
    }

    #[DataProvider('factories')]
    public function test_queue_for_root_async_without_inject(\Closure|array $factory, int $ttl): void
    {
        $app = $this->boot(QueueModule::forRootAsync(['imports' => [SettingsModule::class], 'useFactory' => $factory]));

        self::assertSame($ttl, $app->get(QueueModule::OPTIONS)['ttl']);
    }

    #[DataProvider('factories')]
    public function test_orm_for_root_async_without_inject(\Closure|array $factory, int $ttl): void
    {
        $app = $this->boot(OrmModule::forRootAsync(['imports' => [SettingsModule::class], 'useFactory' => $factory]));

        self::assertSame($ttl, $app->get(OrmModule::OPTIONS)['ttl']);
        self::assertInstanceOf(DataSource::class, $app->get(DataSource::class));
    }
}
